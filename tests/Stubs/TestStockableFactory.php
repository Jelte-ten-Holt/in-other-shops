<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TestStockable>
 */
final class TestStockableFactory extends Factory
{
    protected $model = TestStockable::class;

    public function definition(): array
    {
        return [
            'name' => fake()->word(),
        ];
    }
}
