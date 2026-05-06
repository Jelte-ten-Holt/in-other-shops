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
    ): StockReservation {
        $quantity = abs($quantity);

        $reservation = DB::transaction(
            fn (): StockReservation => $this->reserve($stockable, $quantity, $description, $reference, $source, $reservedUntil, $rejectOversell),
        );

        ReservationCreated::dispatch($reservation);

        return $reservation;
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
