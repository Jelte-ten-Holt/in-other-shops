<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Database\Factories;

use InOtherShops\Inventory\DTOs\Stock;
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
            // The StockCast rejects raw ints; tests that need a specific
            // starting level can either chain ->withLevel(N) below or pass
            // ['stock_level' => new Stock(N)] directly to create().
            'stock_level' => new Stock(0),
            'low_stock_threshold' => null,
        ];
    }

    public function withLevel(int $level): static
    {
        return $this->state(fn (): array => [
            'stock_level' => new Stock($level),
        ]);
    }
}
