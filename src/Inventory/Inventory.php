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

    /**
     * Days an open hold, or an unpaid admin order, may hold stock past its
     * day before it is flagged (stock-holds brief §4.5, D15).
     */
    public static function holdWarnAfterDays(): int
    {
        return (int) config('inventory.hold_warn_after_days', 7);
    }
}
