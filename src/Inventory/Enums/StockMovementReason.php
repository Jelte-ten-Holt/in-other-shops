<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Enums;

use InOtherShops\Support\HasLabel;

enum StockMovementReason: string
{
    use HasLabel;

    case Received = 'received';
    case Restock = 'restock';
    case Sold = 'sold';
    case Reserved = 'reserved';
    case Released = 'released';
    case Adjusted = 'adjusted';
}
