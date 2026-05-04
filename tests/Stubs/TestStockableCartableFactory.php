<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TestStockableCartable>
 */
final class TestStockableCartableFactory extends Factory
{
    protected $model = TestStockableCartable::class;

    public function definition(): array
    {
        return [
            'name' => fake()->word(),
            'description' => fake()->sentence(),
            'tracks_stock' => true,
            'allow_backorder' => false,
        ];
    }
}
