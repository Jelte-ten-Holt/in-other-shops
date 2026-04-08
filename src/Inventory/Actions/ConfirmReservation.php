<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Actions;

use InOtherShops\Inventory\Enums\StockMovementReason;
use InOtherShops\Inventory\Models\StockMovement;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

final class ConfirmReservation
{
    /**
     * Convert reserved movements for a reference into confirmed sold movements.
     *
     * Stock level is unchanged — the reservation already decremented it.
     * This just converts the reason from Reserved to Sold and clears the TTL.
     *
     * @return Collection<int, StockMovement>
     */
    public function __invoke(Model $reference, ?string $description = null): Collection
    {
        $movements = $this->findReservedMovements($reference);

        $this->convertToSold($movements, $description);

        return $movements;
    }

    /**
     * @return Collection<int, StockMovement>
     */
    private function findReservedMovements(Model $reference): Collection
    {
        return StockMovement::where('reference_type', $reference->getMorphClass())
            ->where('reference_id', $reference->getKey())
            ->where('reason', StockMovementReason::Reserved)
            ->get();
    }

    /**
     * @param  Collection<int, StockMovement>  $movements
     */
    private function convertToSold(Collection $movements, ?string $description): void
    {
        foreach ($movements as $movement) {
            $attributes = [
                'reason' => StockMovementReason::Sold,
                'reserved_until' => null,
            ];

            if ($description !== null) {
                $attributes['description'] = $description;
            }

            $movement->update($attributes);
        }
    }
}
