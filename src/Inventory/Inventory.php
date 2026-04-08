<?php

declare(strict_types=1);

namespace InOtherShops\Inventory;

use InOtherShops\Inventory\Models\StockItem;
use InOtherShops\Inventory\Models\StockMovement;

final class Inventory
{
    public static function stockItem(): StockItem
    {
        $class = config('inventory.models.stock_item', StockItem::class);

        return new $class;
    }

    public static function stockMovement(): StockMovement
    {
        $class = config('inventory.models.stock_movement', StockMovement::class);

        return new $class;
    }
}
