<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Database\Factories;

use InOtherShops\Commerce\Cart\Models\CartItem;
use InOtherShops\Commerce\Commerce;
use InOtherShops\Currency\Enums\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CartItem>
 */
final class CartItemFactory extends Factory
{
    public function modelName(): string
    {
        return Commerce::cartItem();
    }

    public function definition(): array
    {
        return [
            'cart_id' => Commerce::cart()::factory(),
            'cartable_type' => 'product',
            'cartable_id' => 1,
            'quantity' => fake()->numberBetween(1, 5),
            'unit_price' => fake()->numberBetween(500, 9999),
            'currency' => Currency::EUR->value,
        ];
    }
}
