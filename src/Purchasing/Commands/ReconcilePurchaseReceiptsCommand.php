<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Commands;

use InOtherShops\Purchasing\Actions\ReconcilePurchaseReceipts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Read-only purchasing tripwire (G11). Reports — never repairs — purchase-order
 * lines whose `quantity_received` counter has drifted from its `Received`
 * movement ledger, exiting non-zero when any has. Not auto-scheduled: consumers
 * wire it into their own scheduler + alerting.
 */
final class ReconcilePurchaseReceiptsCommand extends Command
{
    protected $signature = 'purchasing:reconcile-receipts';

    protected $description = 'Report purchase-order lines whose quantity_received drifts from the received-movement ledger (read-only; exits non-zero on drift)';

    public function handle(ReconcilePurchaseReceipts $reconcile): int
    {
        $report = $reconcile();

        if ($report->isClean()) {
            $this->info('Purchase receipts reconciled clean: every quantity_received matches its received-movement ledger.');

            return self::SUCCESS;
        }

        $this->error(count($report->mismatches).' purchase-order line(s) diverge from the received-movement ledger:');
        $this->table(
            ['line', 'purchase_order', 'recorded', 'ledger', 'delta'],
            array_map(fn (array $m): array => [
                $m['line_id'],
                $m['purchase_order_id'],
                $m['recorded'],
                $m['ledger'],
                $m['recorded'] - $m['ledger'],
            ], $report->mismatches),
        );

        Log::warning('Purchase-receipt reconciliation found drift', [
            'line_mismatches' => count($report->mismatches),
        ]);

        return self::FAILURE;
    }
}
