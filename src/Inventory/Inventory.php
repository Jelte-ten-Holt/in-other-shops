<?php

declare(strict_types=1);

namespace InOtherShops\Inventory;

use InOtherShops\Inventory\Models\StockItem;
use InOtherShops\Inventory\Models\StockMovement;
use InOtherShops\Inventory\Models\StockReservation;

final class Inventory
{
    /** @return class-string<StockItem> */
    public static function stockItem(): string
    {
        return config('inventory.models.stock_item', StockItem::class);
    }

    /** @return class-string<StockMovement> */
    public static function stockMovement(): string
    {
        return config('inventory.models.stock_movement', StockMovement::class);
    }

    /** @return class-string<StockReservation> */
    public static function stockReservation(): string
    {
        return config('inventory.models.stock_reservation', StockReservation::class);
    }
}
