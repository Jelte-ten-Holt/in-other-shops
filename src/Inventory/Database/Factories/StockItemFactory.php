<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Database\Factories;

use InOtherShops\Inventory\Inventory;
use InOtherShops\Inventory\Models\StockItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockItem>
 */
final class StockItemFactory extends Factory
{
    public function modelName(): string
    {
        return Inventory::stockItem();
    }

    public function definition(): array
    {
        return [
            'stock_level' => 0,
            'low_stock_threshold' => null,
        ];
    }
}
