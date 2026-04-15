<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Enums;

enum StockMovementReason: string
{
    case Received = 'received';
    case Restock = 'restock';
    case Sold = 'sold';
    case Reserved = 'reserved';
    case Released = 'released';
    case Adjusted = 'adjusted';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::Restock => 'Restock',
            self::Sold => 'Sold',
            self::Reserved => 'Reserved',
            self::Released => 'Released',
            self::Adjusted => 'Adjusted',
        };
    }
}
