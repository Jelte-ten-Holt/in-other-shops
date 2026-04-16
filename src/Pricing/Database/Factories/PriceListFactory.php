<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Database\Factories;

use InOtherShops\Pricing\Models\PriceList;
use InOtherShops\Pricing\Pricing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceList>
 */
final class PriceListFactory extends Factory
{
    public function modelName(): string
    {
        return Pricing::priceList();
    }

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => ucfirst((string) $name),
            'slug' => str($name)->slug()->toString(),
            'description' => null,
            'is_default' => false,
            'priority' => 0,
        ];
    }

    public function default(): self
    {
        return $this->state(fn () => ['is_default' => true, 'priority' => 100]);
    }
}
