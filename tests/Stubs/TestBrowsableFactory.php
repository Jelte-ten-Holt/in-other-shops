<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TestBrowsable>
 */
final class TestBrowsableFactory extends Factory
{
    protected $model = TestBrowsable::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'description' => fake()->sentence(),
            'published_at' => now(),
        ];
    }
}
