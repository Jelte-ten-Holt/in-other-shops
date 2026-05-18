<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TestCartable>
 */
final class TestCartableFactory extends Factory
{
    protected $model = TestCartable::class;

    public function definition(): array
    {
        return [
            'name' => fake()->word(),
            'description' => fake()->sentence(),
            'unit_price' => 1500,
            'is_pre_order' => false,
            'expected_ship_date' => null,
        ];
    }
}
