<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Actions;

use InOtherShops\Inventory\Enums\StockMovementReason;
use InOtherShops\Inventory\Inventory;
use InOtherShops\Purchasing\DTOs\PurchaseReceiptReconciliationReport;
use InOtherShops\Purchasing\Purchasing;

/**
 * Read-only reconciliation of each purchase-order line's `quantity_received`
 * counter against its `Received` stock-movement ledger (G11). Writes nothing —
 * it reports drift so a scheduled run can act as a tripwire.
 *
 * The ledger is authoritative: every receipt drops a `Received` movement
 * referencing the line (via {@see ReceiveItems}) and bumps the counter in the
 * same transaction, so the two should never diverge — any divergence is a bug
 * elsewhere, surfaced here rather than left to mis-state outstanding quantities.
 */
final class ReconcilePurchaseReceipts
{
    public function __invoke(): PurchaseReceiptReconciliationReport
    {
        $ledgerByLine = $this->receivedQuantityByLine();

        $mismatches = [];

        foreach (Purchasing::purchaseOrderLine()::query()->get() as $line) {
            $recorded = (int) $line->quantity_received;
            $ledger = $ledgerByLine[(int) $line->getKey()] ?? 0;

            if ($recorded === $ledger) {
                continue;
            }

            $mismatches[] = [
                'line_id' => (int) $line->getKey(),
                'purchase_order_id' => (int) $line->purchase_order_id,
                'recorded' => $recorded,
                'ledger' => $ledger,
            ];
        }

        return new PurchaseReceiptReconciliationReport($mismatches);
    }

    /**
     * Sum of `Received` movement quantities per purchase-order line, in one
     * grouped query (no per-line N+1). Keyed by line id.
     *
     * @return array<int, int>
     */
    private function receivedQuantityByLine(): array
    {
        return Inventory::stockMovement()::query()
            ->where('reference_type', 'purchase_order_line')
            ->where('reason', StockMovementReason::Received)
            ->selectRaw('reference_id, SUM(quantity) as received_total')
            ->groupBy('reference_id')
            ->pluck('received_total', 'reference_id')
            ->mapWithKeys(fn ($total, $referenceId): array => [(int) $referenceId => (int) $total])
            ->all();
    }
}
