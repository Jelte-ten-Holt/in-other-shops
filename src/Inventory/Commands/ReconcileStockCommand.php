<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Commands;

use InOtherShops\Inventory\Actions\ReconcileStock;
use InOtherShops\Inventory\DTOs\StockReconciliationReport;
use InOtherShops\Inventory\Inventory;
use InOtherShops\Inventory\Models\StockHold;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Read-only inventory tripwire (F18 / G9 / T6). Reports — never repairs — stock
 * drift and orphaned reservations, and exits non-zero when any is found so a
 * scheduled run surfaces the problem to monitoring instead of letting it rot.
 * The package schedules it (daily, `inventory.schedule.reconcile`); consumers
 * route alerts by listening to InventoryDriftDetected. Stale holds and unpaid
 * admin orders print as warnings and never change the exit code.
 */
final class ReconcileStockCommand extends Command
{
    protected $signature = 'inventory:reconcile';

    protected $description = 'Report stock-level drift and orphaned reservations (read-only; exits non-zero on drift)';

    public function handle(ReconcileStock $reconcile): int
    {
        $report = $reconcile();

        // Stale holds and admin orders are warnings, printed whatever else the
        // report says; they never change the exit code (stock-holds brief D15).
        $this->printWarnings($report);

        if ($report->isClean()) {
            $this->info('Inventory reconciled clean: every stock level matches its ledger and no reservations are orphaned.');

            return self::SUCCESS;
        }

        if ($report->levelMismatches !== []) {
            $this->error(count($report->levelMismatches).' stock level(s) diverge from the movement ledger:');
            $this->table(
                ['stock_item', 'stockable', 'recorded', 'ledger', 'delta'],
                array_map(fn (array $m): array => [
                    $m['stock_item_id'],
                    $m['stockable_type'].'#'.$m['stockable_id'],
                    $m['recorded'],
                    $m['ledger'],
                    $m['recorded'] - $m['ledger'],
                ], $report->levelMismatches),
            );
        }

        if ($report->nullTtlPendingReservationIds !== []) {
            $this->error(count($report->nullTtlPendingReservationIds).' pending reservation(s) have no TTL and belong to no open hold or unpaid order (invisible to the expiry cron): '
                .implode(', ', $report->nullTtlPendingReservationIds));
        }

        if ($report->overduePendingReservationIds !== []) {
            $this->error(count($report->overduePendingReservationIds).' pending reservation(s) are past their TTL but unreleased (expiry cron not running?): '
                .implode(', ', $report->overduePendingReservationIds));
        }

        Log::warning('Inventory reconciliation found drift', [
            'level_mismatches' => count($report->levelMismatches),
            'null_ttl_reservations' => count($report->nullTtlPendingReservationIds),
            'overdue_reservations' => count($report->overduePendingReservationIds),
        ]);

        return self::FAILURE;
    }

    private function printWarnings(StockReconciliationReport $report): void
    {
        $days = Inventory::holdWarnAfterDays();

        if ($report->staleHoldIds !== []) {
            $names = StockHold::query()->whereKey($report->staleHoldIds)->orderBy('id')->get()
                ->map(fn (StockHold $hold): string => "{$hold->name} (#{$hold->getKey()})")
                ->implode(', ');

            $this->warn(count($report->staleHoldIds)." stock hold(s) open more than {$days} days past their day — release them if they are done: {$names}");
        }

        if ($report->staleReferences !== []) {
            $references = implode(', ', array_map(fn (array $reference): string => "{$reference['type']}#{$reference['id']}", $report->staleReferences));

            $this->warn(count($report->staleReferences)." unpaid record(s) holding stock for more than {$days} days: {$references}");
        }
    }
}
