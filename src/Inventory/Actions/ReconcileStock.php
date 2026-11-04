<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Actions;

use InOtherShops\Inventory\Contracts\HasOpenReservations;
use InOtherShops\Inventory\DTOs\StockReconciliationReport;
use InOtherShops\Inventory\Enums\ReservationStatus;
use InOtherShops\Inventory\Events\InventoryDriftDetected;
use InOtherShops\Inventory\Inventory;
use InOtherShops\Inventory\Models\StockHold;
use InOtherShops\Inventory\Models\StockReservation;
use Illuminate\Database\Eloquent\Collection;
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
 *
 * Dispatches {@see InventoryDriftDetected} when the report is dirty — that event
 * is what turns a scheduled run into detection rather than a log line.
 *
 * A Pending row with no TTL is exempt when its reference implements
 * {@see HasOpenReservations} and says it is open — an open stock hold, an
 * unpaid admin order. The exempt ones held past `inventory.hold_warn_after_days`
 * are listed as warnings, which never dirty the report (stock-holds brief D15).
 * A row on a released hold, or on a reference that no longer exists, stays an
 * error.
 */
final class ReconcileStock
{
    public function __invoke(): StockReconciliationReport
    {
        [$orphanIds, $staleHoldIds, $staleReferences] = $this->classifyNullTtlPendingReservations();

        $report = new StockReconciliationReport(
            levelMismatches: $this->findLevelMismatches(),
            nullTtlPendingReservationIds: $orphanIds,
            overduePendingReservationIds: $this->findOverduePendingReservationIds(),
            staleHoldIds: $staleHoldIds,
            staleReferences: $staleReferences,
        );

        // A dirty report is the whole product of this action, so it says so out
        // loud rather than leaving the caller to check. Clean runs stay silent.
        if (! $report->isClean()) {
            InventoryDriftDetected::dispatch($report);
        }

        return $report;
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
     * Pending reservations with no `reserved_until` are invisible to the expiry
     * cron, which filters on `whereNotNull('reserved_until')` (G9). Each is an
     * orphan — an error — unless its reference keeps its reservations open on
     * purpose; those are exempt, and listed as stale once held past the
     * threshold.
     *
     * @return array{list<int>, list<int>, list<array{type: string, id: int}>}
     */
    private function classifyNullTtlPendingReservations(): array
    {
        /** @var Collection<int, StockReservation> $reservations */
        $reservations = Inventory::stockReservation()::query()
            ->where('status', ReservationStatus::Pending)
            ->whereNull('reserved_until')
            ->orderBy('id')
            ->get()
            ->load('reference');

        $orphanIds = [];
        $staleHoldIds = [];
        $staleReferences = [];
        $staleBefore = Carbon::now()->subDays(Inventory::holdWarnAfterDays());

        foreach ($reservations as $reservation) {
            $reference = $reservation->reference;

            if (! $reference instanceof HasOpenReservations || ! $reference->keepsReservationsOpen()) {
                $orphanIds[] = (int) $reservation->getKey();

                continue;
            }

            if ($reference->reservationsOpenSince()->gte($staleBefore)) {
                continue;
            }

            if ($reference instanceof StockHold) {
                $staleHoldIds[(int) $reference->getKey()] = (int) $reference->getKey();
            } else {
                $key = $reference->getMorphClass().':'.$reference->getKey();
                $staleReferences[$key] = ['type' => $reference->getMorphClass(), 'id' => (int) $reference->getKey()];
            }
        }

        return [$orphanIds, array_values($staleHoldIds), array_values($staleReferences)];
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
