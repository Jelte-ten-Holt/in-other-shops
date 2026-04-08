<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Contracts;

use InOtherShops\Inventory\Models\StockItem;
use InOtherShops\Inventory\Models\StockMovement;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphOne;

interface HasStock
{
    /**
     * @return MorphOne<StockItem, $this>
     */
    public function stockItem(): MorphOne;

    /**
     * @return HasManyThrough<StockMovement, StockItem, $this>
     */
    public function stockMovements(): HasManyThrough;

    public function stockLevel(): int;

    public function isInStock(): bool;
}
