<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Actions;

use InOtherShops\Inventory\Enums\StockMovementReason;
use InOtherShops\Inventory\Events\StockReleased;
use InOtherShops\Inventory\Models\StockMovement;
use Illuminate\Support\Collection;

final class ReleaseExpiredReservations
{
    public function __construct(
        private readonly AdjustStock $adjustStock,
    ) {}

    /**
     * @return Collection<int, StockMovement> The release movements created.
     */
    public function __invoke(): Collection
    {
        $expired = $this->findExpiredReservations();

        return $expired->map(fn (StockMovement $movement) => $this->releaseMovement($movement));
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, StockMovement>
     */
    private function findExpiredReservations(): \Illuminate\Database\Eloquent\Collection
    {
        return StockMovement::where('reason', StockMovementReason::Reserved)
            ->whereNotNull('reserved_until')
            ->where('reserved_until', '<=', now())
            ->with('stockItem.stockable')
            ->get();
    }

    private function releaseMovement(StockMovement $movement): StockMovement
    {
        $stockable = $movement->stockItem->stockable;

        $releaseMovement = ($this->adjustStock)(
            stockable: $stockable,
            quantity: abs($movement->quantity),
            reason: StockMovementReason::Released,
            description: "Expired reservation (movement #{$movement->id})",
            reference: $movement->reference,
        );

        $movement->update([
            'reason' => StockMovementReason::Released,
            'reserved_until' => null,
        ]);

        StockReleased::dispatch($movement, $releaseMovement);

        return $releaseMovement;
    }
}
