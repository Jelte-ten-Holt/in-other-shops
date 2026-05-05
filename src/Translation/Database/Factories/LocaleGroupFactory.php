<?php

declare(strict_types=1);

namespace InOtherShops\Translation\Database\Factories;

use InOtherShops\Translation\Models\LocaleGroup;
use InOtherShops\Translation\Translation as TranslationRegistry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LocaleGroup>
 */
final class LocaleGroupFactory extends Factory
{
    public function modelName(): string
    {
        return TranslationRegistry::localeGroup();
    }

    public function definition(): array
    {
        return [
            'shares_inventory' => false,
        ];
    }

    public function sharingInventory(): static
    {
        return $this->state(fn () => ['shares_inventory' => true]);
    }
}
