<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Actions;

use InOtherShops\Inventory\Enums\StockMovementReason;
use InOtherShops\Inventory\Events\StockReleased;
use InOtherShops\Inventory\Inventory;
use InOtherShops\Inventory\Models\StockMovement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
        $candidateIds = $this->findExpiredReservationIds();

        return $candidateIds
            ->map(fn (int $id): ?StockMovement => $this->tryReleaseOne($id))
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, int>
     */
    private function findExpiredReservationIds(): Collection
    {
        $model = Inventory::stockMovement()::class;

        /** @var Collection<int, int> */
        return $model::query()
            ->where('reason', StockMovementReason::Reserved)
            ->whereNotNull('reserved_until')
            ->where('reserved_until', '<=', now())
            ->pluck('id');
    }

    /**
     * Attempt to release a single reservation within its own transaction.
     *
     * Returns null when another worker already handled the row (reason no
     * longer Reserved) or it is no longer past its TTL. The `reason =
     * Reserved` guard inside the locked SELECT is what makes the overall
     * `__invoke` idempotent under concurrent workers.
     */
    private function tryReleaseOne(int $movementId): ?StockMovement
    {
        return DB::transaction(function () use ($movementId): ?StockMovement {
            $model = Inventory::stockMovement()::class;

            /** @var StockMovement|null $movement */
            $movement = $model::query()
                ->where('id', $movementId)
                ->where('reason', StockMovementReason::Reserved)
                ->whereNotNull('reserved_until')
                ->where('reserved_until', '<=', now())
                ->with('stockItem.stockable')
                ->lockForUpdate()
                ->first();

            if ($movement === null) {
                return null;
            }

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
        });
    }
}
