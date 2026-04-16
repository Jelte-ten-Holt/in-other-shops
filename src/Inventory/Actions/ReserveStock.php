<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Actions;

use InOtherShops\Inventory\Contracts\HasStock;
use InOtherShops\Inventory\Enums\ReservationStatus;
use InOtherShops\Inventory\Enums\StockMovementReason;
use InOtherShops\Inventory\Events\ReservationCreated;
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

    /**
     * @param  Model&HasStock  $stockable
     */
    public function __invoke(
        Model $stockable,
        int $quantity,
        ?string $description = null,
        ?Model $reference = null,
        ?string $source = null,
        ?CarbonInterface $reservedUntil = null,
    ): StockReservation {
        $quantity = abs($quantity);

        $reservation = DB::transaction(
            fn (): StockReservation => $this->reserve($stockable, $quantity, $description, $reference, $source, $reservedUntil),
        );

        ReservationCreated::dispatch($reservation);

        return $reservation;
    }

    /**
     * @param  Model&HasStock  $stockable
     */
    private function reserve(
        Model $stockable,
        int $quantity,
        ?string $description,
        ?Model $reference,
        ?string $source,
        ?CarbonInterface $reservedUntil,
    ): StockReservation {
        $movement = ($this->adjustStock)(
            stockable: $stockable,
            quantity: -$quantity,
            reason: StockMovementReason::Reserved,
            description: $description,
            reference: $reference,
            source: $source,
        );

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
