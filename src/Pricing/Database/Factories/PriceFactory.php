<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Database\Factories;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Models\Price;
use InOtherShops\Pricing\Pricing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Price>
 */
final class PriceFactory extends Factory
{
    public function modelName(): string
    {
        return Pricing::price();
    }

    public function definition(): array
    {
        return [
            'currency' => Currency::EUR->value,
            'amount' => 1000,
            'compare_at_amount' => null,
            'minimum_quantity' => 1,
        ];
    }
}
