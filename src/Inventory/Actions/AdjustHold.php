<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InOtherShops\Inventory\Contracts\HasStock;
use InOtherShops\Inventory\Exceptions\InventoryException;
use InOtherShops\Inventory\Models\StockHold;
use InOtherShops\Inventory\Models\StockReservation;
use InOtherShops\Inventory\Support\StockLocks;
use InvalidArgumentException;
use RuntimeException;

/**
 * Brings a hold to holding exactly `$to` of a product (stock-holds brief §4.1)
 * — the hold form's save, for creating and editing alike. The caller passes the
 * number the admin typed, never a delta: under the locks this reads what the
 * hold holds *now* (an order may have drained it since the page loaded) and
 * applies the difference. An increase is one reserve; a reduction releases the
 * product's rows and re-reserves what stays, so the ledger reads release +
 * reserve. Every movement's description names the hold.
 *
 * Holding takes real pieces: an increase reserves with `rejectOversell: true`
 * whatever `allowsBackorder()` says, because a hold is what you pack, and a
 * made-to-order product at negative stock on purpose has nothing to pack. The
 * re-reserve of a reduction claims nothing new, so it passes `false` (on a
 * product already at negative stock the default would throw). Neither expires.
 *
 * Lock order (§4.2): the hold row, then the product's group's stock items, then
 * the hold's rows for the product, then inserts. Returns the quantity held
 * afterwards.
 */
final class AdjustHold
{
    private const string SOURCE = 'dashboard';

    public function __construct(
        private readonly ReserveStock $reserveStock,
        private readonly ReleaseReservation $releaseReservation,
    ) {}

    public function __invoke(StockHold $hold, Model&HasStock $stockable, int $to): int
    {
        $this->ensureHoldable($stockable, $to);

        return DB::transaction(function () use ($hold, $stockable, $to): int {
            $hold = StockLocks::lockHold($hold);
            StockLocks::lock([$stockable]);

            $rows = $this->lockHeldRows($hold, $stockable);
            $held = (int) $rows->sum('quantity');

            if ($to > $held) {
                $this->reserve($hold, $stockable, $to - $held, rejectOversell: true);
            } elseif ($to < $held) {
                $this->releaseAll($hold, $rows);

                if ($to > 0) {
                    $this->reserve($hold, $stockable, $to, rejectOversell: false);
                }
            }

            return $to;
        });
    }

    private function ensureHoldable(Model&HasStock $stockable, int $to): void
    {
        if ($to < 0) {
            throw new InvalidArgumentException("A hold cannot hold a negative quantity ({$to}).");
        }

        if (! $stockable->tracksStock()) {
            throw new InventoryException('A product that does not track stock cannot be held.');
        }
    }

    /**
     * Taken from the locked result set, never a separate aggregate: a
     * consistent read inside the page's transaction is a snapshot from before
     * the wait.
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

    private function reserve(StockHold $hold, Model&HasStock $stockable, int $quantity, bool $rejectOversell): void
    {
        ($this->reserveStock)(
            stockable: $stockable,
            quantity: $quantity,
            description: "Held for {$hold->name}",
            reference: $hold,
            source: self::SOURCE,
            rejectOversell: $rejectOversell,
            expires: false,
        );
    }

    /**
     * @param  Collection<int, StockReservation>  $rows
     */
    private function releaseAll(StockHold $hold, Collection $rows): void
    {
        foreach ($rows as $row) {
            if (($this->releaseReservation)($row, "Taken off hold {$hold->name}") === null) {
                throw new RuntimeException("Reservation {$row->getKey()} of hold {$hold->name} was released under its lock.");
            }
        }
    }
}
