<?php

declare(strict_types=1);

namespace InOtherShops\Variants\Database\Factories;

use InOtherShops\Variants\Models\OptionValue;
use InOtherShops\Variants\Variants;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OptionValue>
 */
final class OptionValueFactory extends Factory
{
    public function modelName(): string
    {
        return Variants::optionValue();
    }

    public function definition(): array
    {
        $optionModel = Variants::option();
        $value = fake()->unique()->word();

        return [
            'option_id' => $optionModel::factory(),
            'value' => Str::slug($value).'-'.Str::lower(Str::random(4)),
            'position' => 0,
        ];
    }
}
