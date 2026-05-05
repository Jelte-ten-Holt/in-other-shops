<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TestStockableLocalizable>
 */
final class TestStockableLocalizableFactory extends Factory
{
    protected $model = TestStockableLocalizable::class;

    public function definition(): array
    {
        return [
            'name' => fake()->word(),
            'locale' => 'en',
        ];
    }
}
