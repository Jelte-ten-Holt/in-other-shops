<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Inventory\Actions\ReleaseReservation;
use InOtherShops\Inventory\Actions\ReserveStock;
use InOtherShops\Inventory\Contracts\HasStock;
use InOtherShops\Inventory\Enums\ReservationStatus;
use InOtherShops\Inventory\Inventory;
use InOtherShops\Inventory\Models\StockHold;
use InOtherShops\Inventory\Models\StockReservation;
use InOtherShops\Inventory\Support\StockLocks;
use RuntimeException;

/**
 * Gives back the stock an order holds — the cancel path and the delete path
 * both (stock-holds brief §4.4, D10). A reservation the order took from a hold
 * that is still open goes back to that hold (the move in reverse): the
 * correction path for a market sale — delete, recreate — must not put the
 * piece on sale online in between, or the recreate finds the hold short. Any
 * other reservation is released, which for a checkout order (no hold) is
 * exactly what the cancel listener always did. The rule is *open hold*: a hold
 * edited down to none of that product since still gets the units back, and
 * shows them.
 *
 * Lock order (§4.2): the holds involved, by id, then every stock item the
 * order's reservations sit on, sorted, then the reservation rows. The move-back
 * re-reserves with `expires: false` and `rejectOversell: false` (it moves
 * units, it claims none).
 *
 * The order is passed in, not read back: in the `deleted` hook the row is gone,
 * so its number and its reference for the release movements come from the
 * in-memory model.
 */
final class ReleaseOrderStock
{
    public function __construct(
        private readonly ReleaseReservation $releaseReservation,
        private readonly ReserveStock $reserveStock,
    ) {}

    /**
     * @param  string|null  $reason  ledger text for a plain release; null keeps each reservation's own
     */
    public function __invoke(Order $order, ?string $reason = null): void
    {
        DB::transaction(function () use ($order, $reason): void {
            $holds = $this->lockHolds($this->heldReservations($order)->pluck('stock_hold_id')->filter()->all());
            StockLocks::lock($this->stockablesOf($this->heldReservations($order)));

            // Which holds and stock items are read before the rows are locked.
            // A row found under the lock on one not yet locked (only possible
            // if this transaction's snapshot predates the locks) gets it locked
            // late, out of the global order, rather than touched unlocked.
            $rows = $this->heldReservations($order, lock: true);
            StockLocks::lock($this->stockablesOf($rows));
            $holds += $this->lockHolds(array_diff($rows->pluck('stock_hold_id')->filter()->all(), array_keys($holds)));

            foreach ($rows as $row) {
                $hold = $row->stock_hold_id === null ? null : ($holds[$row->stock_hold_id] ?? null);

                if ($hold !== null && ! $hold->isReleased()) {
                    $this->moveBack($order, $row, $hold);
                } else {
                    $this->release($order, $row, $reason);
                }
            }
        });
    }

    /**
     * @return Collection<int, StockReservation>
     */
    private function heldReservations(Order $order, bool $lock = false): Collection
    {
        $query = Inventory::stockReservation()::query()
            ->where('reference_type', $order->getMorphClass())
            ->where('reference_id', $order->getKey())
            ->whereIn('status', [ReservationStatus::Pending, ReservationStatus::Confirmed])
            ->with('stockItem.stockable')
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /**
     * Hold rows first, in id order; released ones too, so the decision below
     * reads `released_at` from a locked row.
     *
     * @param  list<int|string>  $holdIds
     * @return array<int, StockHold>
     */
    private function lockHolds(array $holdIds): array
    {
        $holds = [];
        $holdIds = array_values(array_unique(array_map('intval', $holdIds)));
        sort($holdIds);

        foreach (StockHold::query()->whereKey($holdIds)->orderBy('id')->get() as $hold) {
            $holds[(int) $hold->getKey()] = StockLocks::lockHold($hold, mustBeOpen: false);
        }

        return $holds;
    }

    /**
     * @param  iterable<StockReservation>  $rows
     * @return list<Model&HasStock>
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

    private function moveBack(Order $order, StockReservation $row, StockHold $hold): void
    {
        $stockable = $row->stockItem?->stockable;

        if (! $stockable instanceof HasStock) {
            throw new RuntimeException("Reservation {$row->getKey()} of order {$order->order_number} holds stock for a stockable that no longer exists.");
        }

        $description = "Returned from order {$order->order_number} to hold {$hold->name}";

        $this->release($order, $row, $description);

        ($this->reserveStock)(
            stockable: $stockable,
            quantity: (int) $row->quantity,
            description: $description,
            reference: $hold,
            source: 'dashboard',
            rejectOversell: false,
            expires: false,
        );
    }

    private function release(Order $order, StockReservation $row, ?string $description): void
    {
        if (($this->releaseReservation)($row, $description, reference: $order) === null) {
            throw new RuntimeException("Reservation {$row->getKey()} of order {$order->order_number} was released under its lock.");
        }
    }
}
