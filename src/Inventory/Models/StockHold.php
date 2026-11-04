<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use InOtherShops\Inventory\Contracts\HasOpenReservations;
use InOtherShops\Inventory\Contracts\HasStock;
use InOtherShops\Inventory\Database\Factories\StockHoldFactory;
use InOtherShops\Inventory\Enums\ReservationStatus;
use InOtherShops\Inventory\Inventory;
use InOtherShops\Inventory\Support\StockLocks;

/**
 * Stock set aside for a reason — "Markt 15 Nov", "Kept for Ana" — so it cannot
 * sell online (stock-holds brief §4.1). Its lines are the Pending reservations
 * that reference it; what it holds of a product is their sum, read when asked,
 * never stored. It never expires and ends only by {@see \InOtherShops\Inventory\Actions\ReleaseHold}.
 *
 * Every write goes through the hold actions, which take this row `FOR UPDATE`
 * before anything else ({@see \InOtherShops\Inventory\Actions\AdjustHold},
 * {@see \InOtherShops\Inventory\Actions\ReserveFromHold}, ReleaseHold, and the
 * order's move-back). The read helpers here take no lock: they are for showing
 * a hold, not for deciding a move.
 *
 * No `inventory.models` key: new models do not get one (CLAUDE.md, Registry
 * pattern).
 */
class StockHold extends Model implements HasOpenReservations
{
    use HasFactory;

    protected $guarded = [];

    protected static string $factory = StockHoldFactory::class;

    protected function casts(): array
    {
        return [
            'held_for' => 'date',
            'released_at' => 'datetime',
        ];
    }

    /**
     * @return MorphMany<StockReservation, $this>
     */
    public function reservations(): MorphMany
    {
        return $this->morphMany(Inventory::stockReservation(), 'reference');
    }

    /**
     * The hold's lines: what it still holds.
     *
     * @return MorphMany<StockReservation, $this>
     */
    public function pendingReservations(): MorphMany
    {
        return $this->reservations()->where('status', ReservationStatus::Pending);
    }

    public function isReleased(): bool
    {
        return $this->released_at !== null;
    }

    public function keepsReservationsOpen(): bool
    {
        return ! $this->isReleased();
    }

    /**
     * Age runs from the day it is held for when it has one, so "Markt 15 Nov"
     * made on 1 Nov is not flagged a week before the market.
     */
    public function reservationsOpenSince(): CarbonInterface
    {
        return $this->held_for ?? $this->created_at;
    }

    /**
     * Open, and held `inventory.hold_warn_after_days` past its day: a market
     * that ended without anyone pressing Release. A warning, never drift.
     */
    public function isStale(?CarbonInterface $now = null): bool
    {
        return ! $this->isReleased()
            && $this->reservationsOpenSince()->copy()->addDays(Inventory::holdWarnAfterDays())->lt($now ?? now());
    }

    /**
     * How many of a product the hold holds, matched across its
     * shared-inventory locale group.
     */
    public function heldQuantity(Model&HasStock $stockable): int
    {
        $stockItemIds = StockLocks::stockItemIds($stockable);

        if ($stockItemIds === []) {
            return 0;
        }

        return (int) $this->pendingReservations()->whereIn('stock_item_id', $stockItemIds)->sum('quantity');
    }

    /**
     * What the hold holds, keyed by {@see StockLocks::groupKey()}: one
     * entry per stock a product sells from.
     *
     * @return array<string, int>
     */
    public function heldByGroup(): array
    {
        $held = [];

        foreach ($this->heldLines() as ['stockable' => $stockable, 'quantity' => $quantity]) {
            $key = StockLocks::groupKey($stockable);
            $held[$key] = ($held[$key] ?? 0) + $quantity;
        }

        return $held;
    }

    /**
     * What the hold holds, per stockable it was reserved on.
     *
     * @return list<array{stockable: Model&HasStock, quantity: int}>
     */
    public function heldLines(): array
    {
        $lines = [];

        $reservations = $this->pendingReservations()->with('stockItem.stockable')->orderBy('id')->get();

        foreach ($reservations as $reservation) {
            $stockable = $reservation->stockItem?->stockable;

            if (! $stockable instanceof HasStock) {
                continue;
            }

            $key = $stockable->getMorphClass().':'.$stockable->getKey();
            $lines[$key] ??= ['stockable' => $stockable, 'quantity' => 0];
            $lines[$key]['quantity'] += (int) $reservation->quantity;
        }

        return array_values($lines);
    }
}
