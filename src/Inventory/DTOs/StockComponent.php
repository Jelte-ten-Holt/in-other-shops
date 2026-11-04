<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\DTOs;

use Illuminate\Database\Eloquent\Model;
use InOtherShops\Inventory\Contracts\HasStock;

/**
 * One stock-tracking component of a {@see \InOtherShops\Inventory\Contracts\HasStockComponents}
 * sellable, at the quantity one unit of the sellable contains.
 */
final readonly class StockComponent
{
    public function __construct(
        public Model&HasStock $stockable,
        public int $quantity,
    ) {}
}
