<?php

declare(strict_types=1);

namespace InOtherShops\Translation\Database\Factories;

use InOtherShops\Translation\Models\Translation;
use InOtherShops\Translation\Translation as TranslationRegistry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Translation>
 */
final class TranslationFactory extends Factory
{
    public function modelName(): string
    {
        return TranslationRegistry::translation();
    }

    public function definition(): array
    {
        return [
            'translatable_type' => 'category',
            'translatable_id' => 1,
            'locale' => 'en',
            'field' => 'name',
            'value' => fake()->word(),
        ];
    }
}
