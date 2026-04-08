<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Actions;

use InOtherShops\Inventory\Contracts\HasStock;
use InOtherShops\Inventory\Enums\StockMovementReason;
use InOtherShops\Inventory\Events\StockAdjusted;
use InOtherShops\Inventory\Models\StockItem;
use InOtherShops\Inventory\Models\StockMovement;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class AdjustStock
{
    /**
     * @param  Model&HasStock  $stockable
     */
    public function __invoke(
        Model $stockable,
        int $quantity,
        StockMovementReason $reason,
        ?string $description = null,
        ?Model $reference = null,
        ?string $source = null,
        ?CarbonInterface $reservedUntil = null,
    ): StockMovement {
        $this->validateSource($source);

        [$movement, $stockItem] = DB::transaction(function () use ($stockable, $quantity, $reason, $description, $reference, $source, $reservedUntil): array {
            $stockItem = $this->findOrCreateStockItem($stockable);

            $movement = $this->createMovement($stockItem, $quantity, $reason, $description, $reference, $source, $reservedUntil);

            $this->updateStockLevel($stockItem, $quantity);

            return [$movement, $stockItem->refresh()];
        });

        StockAdjusted::dispatch($movement, $stockItem);

        return $movement;
    }

    /**
     * @param  Model&HasStock  $stockable
     */
    private function findOrCreateStockItem(Model $stockable): StockItem
    {
        return $stockable->stockItem()->firstOrCreate([], ['stock_level' => 0]);
    }

    private function createMovement(
        StockItem $stockItem,
        int $quantity,
        StockMovementReason $reason,
        ?string $description,
        ?Model $reference,
        ?string $source,
        ?CarbonInterface $reservedUntil,
    ): StockMovement {
        $attributes = [
            'quantity' => $quantity,
            'reason' => $reason,
            'description' => $description,
            'source' => $source,
            'reserved_until' => $reservedUntil,
        ];

        if ($reference !== null) {
            $attributes['reference_type'] = $reference->getMorphClass();
            $attributes['reference_id'] = $reference->getKey();
        }

        return $stockItem->movements()->create($attributes);
    }

    private function updateStockLevel(StockItem $stockItem, int $quantity): void
    {
        $stockItem->increment('stock_level', $quantity);
    }

    private function validateSource(?string $source): void
    {
        if ($source === null) {
            return;
        }

        $configured = config('inventory.sources');

        if ($configured === null || $configured === []) {
            return;
        }

        if (! array_key_exists($source, $configured)) {
            throw new InvalidArgumentException(
                "Invalid stock movement source [{$source}]. Allowed: ".implode(', ', array_keys($configured)).'.',
            );
        }
    }
}
