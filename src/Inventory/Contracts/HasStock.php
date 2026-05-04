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

    /**
     * Whether stock movements should affect this model. Untracked items
     * (e.g. digital products with unlimited supply) return false; callers
     * skip reservation/decrement and `isInStock()` returns true regardless
     * of `stockLevel()`.
     */
    public function tracksStock(): bool;

    /**
     * Whether the consuming project allows this stockable to be sold past
     * its current stock level. The Cart and Reservation actions consult
     * this to decide whether to reject an oversell. Default: false.
     */
    public function allowsBackorder(): bool;
}
