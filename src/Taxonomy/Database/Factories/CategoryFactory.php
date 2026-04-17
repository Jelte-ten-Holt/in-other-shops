<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Database\Factories;

use InOtherShops\Taxonomy\Models\Category;
use InOtherShops\Taxonomy\Taxonomy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
final class CategoryFactory extends Factory
{
    public function modelName(): string
    {
        return Taxonomy::category();
    }

    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'position' => 0,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }
}
