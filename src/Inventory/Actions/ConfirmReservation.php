<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Actions;

use InOtherShops\Inventory\Enums\ReservationStatus;
use InOtherShops\Inventory\Events\ReservationConfirmed;
use InOtherShops\Inventory\Inventory;
use InOtherShops\Inventory\Models\StockReservation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Transition reservations for a reference from Pending to Confirmed.
 *
 * Stock level is unchanged — the reservation already decremented it at
 * reserve time. This is a lifecycle transition on the reservation entity,
 * not a new ledger entry. The `-X Reserved` movement on the ledger stays
 * untouched: it remains the honest historical record of the decrement.
 */
final class ConfirmReservation
{
    /**
     * @return Collection<int, StockReservation>
     */
    public function __invoke(Model $reference, ?string $description = null): Collection
    {
        return DB::transaction(
            fn (): Collection => $this->confirmPending($reference, $description),
        );
    }

    /**
     * @return Collection<int, StockReservation>
     */
    private function confirmPending(Model $reference, ?string $description): Collection
    {
        $model = Inventory::stockReservation();

        /** @var Collection<int, StockReservation> $reservations */
        $reservations = $model::query()
            ->where('reference_type', $reference->getMorphClass())
            ->where('reference_id', $reference->getKey())
            ->where('status', ReservationStatus::Pending)
            ->lockForUpdate()
            ->get();

        return $reservations
            ->map(fn (StockReservation $reservation): ?StockReservation => $this->confirm($reservation, $description))
            ->filter()
            ->values();
    }

    private function confirm(StockReservation $reservation, ?string $description): ?StockReservation
    {
        if ($reservation->status !== ReservationStatus::Pending) {
            return null;
        }

        $attributes = [
            'status' => ReservationStatus::Confirmed,
            'resolved_at' => now(),
        ];

        if ($description !== null) {
            $attributes['description'] = $description;
        }

        $reservation->update($attributes);

        ReservationConfirmed::dispatch($reservation);

        return $reservation;
    }
}
