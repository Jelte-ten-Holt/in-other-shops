<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Actions;

use InOtherShops\Inventory\Contracts\HasStock;
use InOtherShops\Inventory\Enums\ReservationStatus;
use InOtherShops\Inventory\Enums\StockMovementReason;
use InOtherShops\Inventory\Events\ReservationCreated;
use InOtherShops\Inventory\Events\StockReservationFailed;
use InOtherShops\Inventory\Exceptions\InsufficientStockException;
use InOtherShops\Inventory\Inventory;
use InOtherShops\Inventory\Models\StockReservation;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ReserveStock
{
    public function __construct(
        private readonly AdjustStock $adjustStock,
    ) {}

    public function __invoke(
        Model&HasStock $stockable,
        int $quantity,
        ?string $description = null,
        ?Model $reference = null,
        ?string $source = null,
        ?CarbonInterface $reservedUntil = null,
        bool $rejectOversell = true,
        bool $expires = true,
    ): StockReservation {
        $quantity = abs($quantity);
        $reservedUntil = $this->resolveReservedUntil($reservedUntil, $expires);

        $reservation = DB::transaction(
            fn (): StockReservation => $this->reserve($stockable, $quantity, $description, $reference, $source, $reservedUntil, $rejectOversell),
        );

        ReservationCreated::dispatch($reservation);

        return $reservation;
    }

    /**
     * A Pending reservation with no `reserved_until` is invisible to
     * `inventory:release-expired` forever — its `Reserved` decrement leaks and
     * stock drifts down silently (G9). So when a caller omits the TTL we apply
     * the configured default (`inventory.reservation_ttl`, in minutes) here, in
     * the one sanctioned reservation path, rather than trusting every caller
     * (admin tooling, a future API, an agent/Variants flow) to remember it. An
     * explicit `reservedUntil` always wins; setting the config to `null` is the
     * deliberate opt-out for a permanent hold (still surfaced by
     * `inventory:reconcile`).
     *
     * `expires: false` asks for a reservation with no TTL whatever the config
     * says: a stock hold, or an admin order waiting days for a bank transfer
     * (stock-holds brief §4.3). Reconcile exempts those through the reference
     * ({@see \InOtherShops\Inventory\Contracts\HasOpenReservations}). Passing a
     * `reservedUntil` as well is a contradiction in the call, so it throws.
     */
    private function resolveReservedUntil(?CarbonInterface $reservedUntil, bool $expires): ?CarbonInterface
    {
        if (! $expires) {
            if ($reservedUntil !== null) {
                throw new InvalidArgumentException('A reservation that does not expire cannot carry a reservedUntil.');
            }

            return null;
        }

        if ($reservedUntil !== null) {
            return $reservedUntil;
        }

        $ttlMinutes = config('inventory.reservation_ttl');

        if ($ttlMinutes === null) {
            return null;
        }

        return now()->addMinutes((int) $ttlMinutes);
    }

    /**
     * Verify that the stock level hasn't gone negative after the adjustment.
     * Called while the FOR UPDATE lock is held so no concurrent writer can
     * interleave. If the level is negative, the surrounding transaction
     * rolls back, undoing the movement.
     */
    private function assertNotOversold(Model&HasStock $stockable, int $quantity): void
    {
        $stockable->unsetRelation('stockItem');
        $currentLevel = $stockable->stockLevel();

        if ($currentLevel >= 0) {
            return;
        }

        $available = $currentLevel + $quantity;

        StockReservationFailed::dispatch($stockable, $quantity, $available);

        throw InsufficientStockException::forReservation($stockable, $quantity, $available);
    }

    private function reserve(
        Model&HasStock $stockable,
        int $quantity,
        ?string $description,
        ?Model $reference,
        ?string $source,
        ?CarbonInterface $reservedUntil,
        bool $rejectOversell = true,
    ): StockReservation {
        // AdjustStock acquires a FOR UPDATE lock on the stock_items row,
        // so the availability check MUST happen after it — otherwise two
        // concurrent reservations can both pass the check before either
        // acquires the lock. We adjust first, then verify the resulting
        // stock level is non-negative. If it went negative, the whole
        // transaction rolls back.
        $movement = ($this->adjustStock)(
            stockable: $stockable,
            quantity: -$quantity,
            reason: StockMovementReason::Reserved,
            description: $description,
            reference: $reference,
            source: $source,
        );

        if ($rejectOversell) {
            $this->assertNotOversold($stockable, $quantity);
        }

        $model = Inventory::stockReservation();

        /** @var StockReservation $reservation */
        $reservation = $model::query()->create([
            'stock_item_id' => $movement->stock_item_id,
            'reserve_movement_id' => $movement->id,
            'quantity' => $quantity,
            'status' => ReservationStatus::Pending,
            'reserved_until' => $reservedUntil,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'description' => $description,
            'source' => $source,
        ]);

        return $reservation;
    }
}
