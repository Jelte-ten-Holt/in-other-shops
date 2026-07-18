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
     * its current stock level. Default: false.
     *
     * Consulted by the CART gate only (`EnsureCartableInStock`), NOT by the
     * reservation actions: `ReserveStock` reads its own `$rejectOversell`
     * parameter and never calls this method. A consumer whose checkout must
     * honour backorders passes the pairing itself from its ReserveItems step —
     * `rejectOversell: ! $cartable->allowsBackorder()` — or a backorderable
     * item adds to the cart and is then refused at reservation. The pairing
     * stays consumer-side deliberately: wiring it into `ReserveStock` would
     * silently change the oversell behaviour of existing consumers.
     */
    public function allowsBackorder(): bool;
}
