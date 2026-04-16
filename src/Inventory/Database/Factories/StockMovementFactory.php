<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Database\Factories;

use InOtherShops\Inventory\Enums\StockMovementReason;
use InOtherShops\Inventory\Inventory;
use InOtherShops\Inventory\Models\StockMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovement>
 */
final class StockMovementFactory extends Factory
{
    public function modelName(): string
    {
        return Inventory::stockMovement()::class;
    }

    public function definition(): array
    {
        return [
            'quantity' => 1,
            'reason' => StockMovementReason::Adjusted,
            'description' => null,
            'source' => null,
        ];
    }
}
