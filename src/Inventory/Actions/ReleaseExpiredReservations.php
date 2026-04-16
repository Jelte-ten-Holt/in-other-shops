<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Actions;

use InOtherShops\Inventory\Enums\ReservationStatus;
use InOtherShops\Inventory\Inventory;
use InOtherShops\Inventory\Models\StockReservation;
use Illuminate\Support\Collection;

final class ReleaseExpiredReservations
{
    public function __construct(
        private readonly ReleaseReservation $releaseReservation,
    ) {}

    /**
     * @return Collection<int, StockReservation> The released reservations.
     */
    public function __invoke(): Collection
    {
        return $this->findExpiredIds()
            ->map(fn (int $id): ?StockReservation => $this->tryRelease($id))
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, int>
     */
    private function findExpiredIds(): Collection
    {
        $model = Inventory::stockReservation()::class;

        /** @var Collection<int, int> */
        return $model::query()
            ->where('status', ReservationStatus::Pending)
            ->whereNotNull('reserved_until')
            ->where('reserved_until', '<=', now())
            ->pluck('id');
    }

    private function tryRelease(int $reservationId): ?StockReservation
    {
        $model = Inventory::stockReservation()::class;

        /** @var StockReservation|null $reservation */
        $reservation = $model::query()->find($reservationId);

        if ($reservation === null) {
            return null;
        }

        return ($this->releaseReservation)($reservation);
    }
}
