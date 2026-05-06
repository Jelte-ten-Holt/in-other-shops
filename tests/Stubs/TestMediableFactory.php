<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TestMediable>
 */
final class TestMediableFactory extends Factory
{
    protected $model = TestMediable::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
        ];
    }
}
