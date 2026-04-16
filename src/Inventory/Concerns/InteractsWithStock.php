<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Concerns;

use InOtherShops\Inventory\Inventory;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphOne;

trait InteractsWithStock
{
    public function stockItem(): MorphOne
    {
        $model = Inventory::stockItem();

        return $this->morphOne($model, 'stockable');
    }

    public function stockMovements(): HasManyThrough
    {
        $stockItemModel = Inventory::stockItem();
        $stockMovementModel = Inventory::stockMovement();

        return $this->hasManyThrough(
            $stockMovementModel,
            $stockItemModel,
            firstKey: 'stockable_id',
            secondKey: 'stock_item_id',
        )->where('stock_items.stockable_type', $this->getMorphClass());
    }

    public function stockLevel(): int
    {
        return $this->stockItem?->stock_level ?? 0;
    }

    public function isInStock(): bool
    {
        return $this->stockLevel() > 0;
    }
}
