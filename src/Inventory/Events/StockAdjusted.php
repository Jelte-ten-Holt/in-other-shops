<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Events;

use InOtherShops\Inventory\Models\StockItem;
use InOtherShops\Inventory\Models\StockMovement;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class StockAdjusted
{
    use Dispatchable;

    public function __construct(
        public StockMovement $movement,
        public StockItem $stockItem,
    ) {}
}
