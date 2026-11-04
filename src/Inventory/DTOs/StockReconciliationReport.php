<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\DTOs;

/**
 * The result of a read-only inventory reconciliation pass (F18 / G9 / T6). Three
 * independent drift signals, each of which means the stock figures can no longer
 * be trusted without investigation:
 *
 *  - level mismatches — a `StockItem.stock_level` that no longer equals the sum
 *    of its movement ledger (the ledger is the source of truth; the aggregate
 *    drifted, e.g. a write that bypassed {@see \InOtherShops\Inventory\Actions\AdjustStock});
 *  - null-TTL pending reservations — Pending rows with no `reserved_until`, which
 *    the expiry cron skips forever (G9), permanently leaking a Reserved decrement;
 *  - overdue pending reservations — Pending rows whose `reserved_until` is already
 *    past, i.e. the expiry cron should have released them and hasn't (it is not
 *    running, or is failing).
 *
 * A Pending row with no TTL is not drift when its reference says its
 * reservations are legitimately open ({@see \InOtherShops\Inventory\Contracts\HasOpenReservations}:
 * an open stock hold, an unpaid admin order). Those held past
 * `inventory.hold_warn_after_days` are listed as **warnings** — stale holds and
 * other stale references — which `isClean()` ignores (stock-holds brief D15):
 * they do not fail the nightly run or fire InventoryDriftDetected.
 */
final readonly class StockReconciliationReport
{
    /**
     * @param  list<array{stock_item_id: int, stockable_type: ?string, stockable_id: ?int, recorded: int, ledger: int}>  $levelMismatches
     * @param  list<int>  $nullTtlPendingReservationIds
     * @param  list<int>  $overduePendingReservationIds
     * @param  list<int>  $staleHoldIds
     * @param  list<array{type: string, id: int}>  $staleReferences  every other stale reference, e.g. an unpaid admin order
     */
    public function __construct(
        public array $levelMismatches,
        public array $nullTtlPendingReservationIds,
        public array $overduePendingReservationIds,
        public array $staleHoldIds = [],
        public array $staleReferences = [],
    ) {}

    public function isClean(): bool
    {
        return $this->levelMismatches === []
            && $this->nullTtlPendingReservationIds === []
            && $this->overduePendingReservationIds === [];
    }

    /** Stale holds and references: warnings, never drift. */
    public function hasWarnings(): bool
    {
        return $this->staleHoldIds !== [] || $this->staleReferences !== [];
    }

    public function issueCount(): int
    {
        return count($this->levelMismatches)
            + count($this->nullTtlPendingReservationIds)
            + count($this->overduePendingReservationIds);
    }
}
