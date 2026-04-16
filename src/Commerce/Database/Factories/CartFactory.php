<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Database\Factories;

use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Commerce\Commerce;
use InOtherShops\Currency\Enums\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cart>
 */
final class CartFactory extends Factory
{
    public function modelName(): string
    {
        return Commerce::cart();
    }

    public function definition(): array
    {
        return [
            'session_token' => fake()->unique()->uuid(),
            'currency' => Currency::EUR->value,
        ];
    }
}
