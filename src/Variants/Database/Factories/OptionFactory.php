<?php

declare(strict_types=1);

namespace InOtherShops\Variants\Database\Factories;

use InOtherShops\Variants\Models\Option;
use InOtherShops\Variants\Variants;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Option>
 */
final class OptionFactory extends Factory
{
    public function modelName(): string
    {
        return Variants::option();
    }

    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'position' => 0,
        ];
    }
}
