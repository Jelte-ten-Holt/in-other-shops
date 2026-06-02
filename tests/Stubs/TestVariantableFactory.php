<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TestVariantable>
 */
final class TestVariantableFactory extends Factory
{
    protected $model = TestVariantable::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
        ];
    }
}
