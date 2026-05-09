<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TestPriceable>
 */
final class TestPriceableFactory extends Factory
{
    protected $model = TestPriceable::class;

    public function definition(): array
    {
        return [
            'name' => fake()->word(),
        ];
    }
}
