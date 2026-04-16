<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Actions;

use InOtherShops\Inventory\Enums\ReservationStatus;
use InOtherShops\Inventory\Enums\StockMovementReason;
use InOtherShops\Inventory\Events\ReservationReleased;
use InOtherShops\Inventory\Events\StockReleased;
use InOtherShops\Inventory\Inventory;
use InOtherShops\Inventory\Models\StockReservation;
use Illuminate\Support\Facades\DB;

/**
 * Release a single pending reservation — appends a `+X Released` ledger
 * movement and transitions the reservation Pending → Released.
 *
 * Returns null when the reservation is not (or no longer) Pending. The
 * locked select + status guard makes concurrent calls idempotent.
 */
final class ReleaseReservation
{
    public function __construct(
        private readonly AdjustStock $adjustStock,
    ) {}

    public function __invoke(StockReservation $reservation): ?StockReservation
    {
        $released = DB::transaction(
            fn (): ?StockReservation => $this->release($reservation->getKey()),
        );

        if ($released !== null) {
            ReservationReleased::dispatch($released);
            StockReleased::dispatch($released, $released->releaseMovement);
        }

        return $released;
    }

    private function release(int $reservationId): ?StockReservation
    {
        $model = Inventory::stockReservation()::class;

        /** @var StockReservation|null $reservation */
        $reservation = $model::query()
            ->where('id', $reservationId)
            ->where('status', ReservationStatus::Pending)
            ->with('stockItem.stockable')
            ->lockForUpdate()
            ->first();

        if ($reservation === null) {
            return null;
        }

        $stockable = $reservation->stockItem->stockable;

        $releaseMovement = ($this->adjustStock)(
            stockable: $stockable,
            quantity: $reservation->quantity,
            reason: StockMovementReason::Released,
            description: $reservation->description,
            reference: $reservation->reference,
            source: $reservation->source,
        );

        $reservation->update([
            'status' => ReservationStatus::Released,
            'release_movement_id' => $releaseMovement->id,
            'resolved_at' => now(),
        ]);

        return $reservation;
    }
}
