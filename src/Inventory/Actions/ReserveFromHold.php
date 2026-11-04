<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InOtherShops\Inventory\Contracts\HasStock;
use InOtherShops\Inventory\DTOs\HoldMove;
use InOtherShops\Inventory\Models\StockHold;
use InOtherShops\Inventory\Models\StockReservation;
use InOtherShops\Inventory\Support\StockLocks;
use RuntimeException;

/**
 * Moves units from a hold to a reference — the only way units leave a hold for
 * an order (stock-holds brief §4.2), and the seam a future customer self-hold
 * would consume through (D9).
 *
 * Under the locks it reads what the hold holds of the product (matched across
 * its shared-inventory locale group), releases those rows, reserves
 * `min(quantity, held)` for the reference and re-reserves the rest for the
 * hold. `stock_level` ends where it started; the ledger shows the release and
 * the two reserves, each with the caller's description. The reservation made
 * for the reference records the hold (`stock_hold_id`, D10), so deleting or
 * cancelling the order can give the units back. The shortfall is returned for
 * the caller to take from main stock (D5).
 *
 * Both reserves move units and claim none, so they pass `rejectOversell: false`
 * (on a product at negative stock the default would throw) and `expires: false`
 * (or the remainder would go back on sale when a TTL ran out).
 *
 * Lock order (§4.2): the hold row (refused if released), then the product's
 * group's stock items, then the hold's rows for the product, then inserts.
 * Everything commits or rolls back together because the actions it calls nest
 * inside this transaction as savepoints — the guarantee is this transaction's,
 * and their events dispatch inside it.
 */
final class ReserveFromHold
{
    private const string SOURCE = 'dashboard';

    public function __construct(
        private readonly ReserveStock $reserveStock,
        private readonly ReleaseReservation $releaseReservation,
    ) {}

    public function __invoke(
        StockHold $hold,
        Model&HasStock $stockable,
        int $quantity,
        Model $reference,
        string $description,
    ): HoldMove {
        return DB::transaction(function () use ($hold, $stockable, $quantity, $reference, $description): HoldMove {
            $hold = StockLocks::lockHold($hold);
            StockLocks::lock([$stockable]);

            $rows = $this->lockHeldRows($hold, $stockable);
            $held = (int) $rows->sum('quantity');
            $fromHold = min($quantity, $held);

            if ($fromHold === 0) {
                return new HoldMove(null, 0, $quantity);
            }

            $holdStockable = $this->stockableOf($rows->first());

            $this->releaseAll($hold, $rows, $description);
            $reservation = $this->reserveFor($reference, $stockable, $fromHold, $description, $hold);

            if ($held > $fromHold) {
                $this->reserveFor($hold, $holdStockable, $held - $fromHold, $description);
            }

            return new HoldMove($reservation, $fromHold, $quantity - $fromHold);
        });
    }

    /**
     * Taken from the locked result set, never a separate aggregate: a
     * consistent read inside the page's transaction is a snapshot from before
     * the wait and would still show rows another move has since released.
     *
     * @return Collection<int, StockReservation>
     */
    private function lockHeldRows(StockHold $hold, Model&HasStock $stockable): Collection
    {
        return $hold->pendingReservations()
            ->whereIn('stock_item_id', StockLocks::stockItemIds($stockable))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * The remainder goes back on the stockable the hold held it on.
     */
    private function stockableOf(StockReservation $row): Model&HasStock
    {
        $stockable = $row->stockItem?->stockable;

        if (! $stockable instanceof HasStock) {
            throw new RuntimeException("Reservation {$row->getKey()} holds stock for a stockable that no longer exists.");
        }

        return $stockable;
    }

    /**
     * @param  Collection<int, StockReservation>  $rows
     */
    private function releaseAll(StockHold $hold, Collection $rows, string $description): void
    {
        foreach ($rows as $row) {
            if (($this->releaseReservation)($row, $description) === null) {
                throw new RuntimeException("Reservation {$row->getKey()} of hold {$hold->name} was released under its lock.");
            }
        }
    }

    private function reserveFor(Model $reference, Model&HasStock $stockable, int $quantity, string $description, ?StockHold $from = null): StockReservation
    {
        $reservation = ($this->reserveStock)(
            stockable: $stockable,
            quantity: $quantity,
            description: $description,
            reference: $reference,
            source: self::SOURCE,
            rejectOversell: false,
            expires: false,
        );

        if ($from !== null) {
            $reservation->forceFill(['stock_hold_id' => $from->getKey()])->save();
        }

        return $reservation;
    }
}
