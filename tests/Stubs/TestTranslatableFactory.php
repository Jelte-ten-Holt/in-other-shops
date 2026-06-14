<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TestTranslatable>
 */
final class TestTranslatableFactory extends Factory
{
    protected $model = TestTranslatable::class;

    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(),
        ];
    }
}
