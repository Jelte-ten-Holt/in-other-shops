<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Actions;

use Illuminate\Support\Facades\DB;
use InOtherShops\Inventory\Contracts\HasStock;
use InOtherShops\Inventory\Models\StockHold;
use InOtherShops\Inventory\Models\StockReservation;
use InOtherShops\Inventory\Support\StockLocks;
use RuntimeException;

/**
 * Ends a hold: gives back whatever it still holds and stamps `released_at`,
 * in one transaction (stock-holds brief §4.1). A released hold is read-only.
 *
 * Releasing again is an idempotent **sweep**, not a no-op on the flag: it
 * gives back any Pending row still referencing the hold. That is also the
 * repair for a row that reached a released hold by a path the brief did not
 * foresee, which `inventory:reconcile` reports as an error.
 *
 * Lock order (§4.2): the hold row, then the stock items its rows sit on,
 * sorted, then the rows. Which stock items those are is read before the rows
 * are locked; the hold-row lock means no other hold actor is mid-move by then,
 * but a row found under the lock on a stock item not yet locked (only possible
 * if this transaction's snapshot predates the hold lock) has its stock item
 * locked late, out of the global order, rather than released unlocked.
 */
final class ReleaseHold
{
    public function __construct(
        private readonly ReleaseReservation $releaseReservation,
    ) {}

    public function __invoke(StockHold $hold): StockHold
    {
        return DB::transaction(function () use ($hold): StockHold {
            $hold = StockLocks::lockHold($hold, mustBeOpen: false);

            StockLocks::lock($this->stockablesOf($hold->pendingReservations()->with('stockItem.stockable')->get()));

            $rows = $hold->pendingReservations()->with('stockItem.stockable')->orderBy('id')->lockForUpdate()->get();
            StockLocks::lock($this->stockablesOf($rows));

            foreach ($rows as $row) {
                $this->release($hold, $row);
            }

            if (! $hold->isReleased()) {
                $hold->forceFill(['released_at' => now()])->save();
            }

            return $hold;
        });
    }

    /**
     * @param  iterable<StockReservation>  $rows
     * @return list<\Illuminate\Database\Eloquent\Model&HasStock>
     */
    private function stockablesOf(iterable $rows): array
    {
        $stockables = [];

        foreach ($rows as $row) {
            $stockable = $row->stockItem?->stockable;

            if ($stockable instanceof HasStock) {
                $stockables[] = $stockable;
            }
        }

        return $stockables;
    }

    private function release(StockHold $hold, StockReservation $row): void
    {
        if (($this->releaseReservation)($row, "Hold {$hold->name} released") === null) {
            throw new RuntimeException("Reservation {$row->getKey()} of hold {$hold->name} was released under its lock.");
        }
    }
}
