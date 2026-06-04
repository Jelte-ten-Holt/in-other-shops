<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Actions;

use InOtherShops\Inventory\DTOs\StockReconciliationReport;
use InOtherShops\Inventory\Enums\ReservationStatus;
use InOtherShops\Inventory\Inventory;
use Illuminate\Support\Carbon;

/**
 * Read-only reconciliation of inventory state against its source of truth (F18 /
 * G9 / T6). Writes nothing — it only reports drift so a scheduled run can act as
 * a tripwire. The three checks are independent; see {@see StockReconciliationReport}.
 *
 * The movement ledger is authoritative: `stock_level` is a maintained aggregate,
 * and the only sanctioned writer ({@see AdjustStock}) keeps the two in lockstep,
 * so any divergence is by definition a bug elsewhere — surfaced here rather than
 * left to quietly mis-state availability.
 */
final class ReconcileStock
{
    public function __invoke(): StockReconciliationReport
    {
        return new StockReconciliationReport(
            levelMismatches: $this->findLevelMismatches(),
            nullTtlPendingReservationIds: $this->findNullTtlPendingReservationIds(),
            overduePendingReservationIds: $this->findOverduePendingReservationIds(),
        );
    }

    /**
     * @return list<array{stock_item_id: int, stockable_type: ?string, stockable_id: ?int, recorded: int, ledger: int}>
     */
    private function findLevelMismatches(): array
    {
        $model = Inventory::stockItem();

        $mismatches = [];

        // Catalogue is small by design — a single pass with the ledger summed in
        // SQL is fine; no chunking needed at this scale.
        foreach ($model::query()->withSum('movements as ledger_total', 'quantity')->get() as $item) {
            $ledger = (int) ($item->getAttribute('ledger_total') ?? 0);
            $recorded = (int) $item->stock_level;

            if ($recorded === $ledger) {
                continue;
            }

            $mismatches[] = [
                'stock_item_id' => (int) $item->getKey(),
                'stockable_type' => $item->stockable_type,
                'stockable_id' => $item->stockable_id === null ? null : (int) $item->stockable_id,
                'recorded' => $recorded,
                'ledger' => $ledger,
            ];
        }

        return $mismatches;
    }

    /**
     * Pending reservations with no `reserved_until` — invisible to the expiry
     * cron, which filters on `whereNotNull('reserved_until')` (G9).
     *
     * @return list<int>
     */
    private function findNullTtlPendingReservationIds(): array
    {
        return Inventory::stockReservation()::query()
            ->where('status', ReservationStatus::Pending)
            ->whereNull('reserved_until')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Pending reservations already past their TTL — the expiry cron should have
     * released these and hasn't (T6).
     *
     * @return list<int>
     */
    private function findOverduePendingReservationIds(): array
    {
        return Inventory::stockReservation()::query()
            ->where('status', ReservationStatus::Pending)
            ->whereNotNull('reserved_until')
            ->where('reserved_until', '<', Carbon::now())
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
