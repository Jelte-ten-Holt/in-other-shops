<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TestLocalizable>
 */
final class TestLocalizableFactory extends Factory
{
    protected $model = TestLocalizable::class;

    public function definition(): array
    {
        return [
            'title' => fake()->sentence(),
            'slug' => fake()->slug(),
            'locale' => 'en',
        ];
    }
}
