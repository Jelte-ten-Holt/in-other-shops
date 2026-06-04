<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Commands;

use InOtherShops\Inventory\Actions\ReconcileStock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Read-only inventory tripwire (F18 / G9 / T6). Reports — never repairs — stock
 * drift and orphaned reservations, and exits non-zero when any is found so a
 * scheduled run surfaces the problem to monitoring instead of letting it rot.
 * Not auto-scheduled: consumers wire it into their own scheduler + alerting (the
 * package doesn't know where a given consumer routes alerts).
 */
final class ReconcileStockCommand extends Command
{
    protected $signature = 'inventory:reconcile';

    protected $description = 'Report stock-level drift and orphaned reservations (read-only; exits non-zero on drift)';

    public function handle(ReconcileStock $reconcile): int
    {
        $report = $reconcile();

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
            $this->error(count($report->nullTtlPendingReservationIds).' pending reservation(s) have no TTL (invisible to the expiry cron): '
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
}
