<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Database\Factories;

use InOtherShops\Taxonomy\Models\Tag;
use InOtherShops\Taxonomy\Taxonomy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tag>
 */
final class TagFactory extends Factory
{
    public function modelName(): string
    {
        return Taxonomy::tag();
    }

    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'type' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }

    public function hidden(): static
    {
        return $this->state(fn () => [
            'type' => 'hidden_on_front',
        ]);
    }
}
