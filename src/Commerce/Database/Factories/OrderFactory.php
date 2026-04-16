<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Database\Factories;

use InOtherShops\Commerce\Commerce;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Currency\Enums\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
final class OrderFactory extends Factory
{
    public function modelName(): string
    {
        return Commerce::order();
    }

    public function definition(): array
    {
        $subtotal = 10000;

        return [
            'order_number' => strtoupper(fake()->unique()->bothify('ORD-####-????')),
            'status' => OrderStatus::Pending,
            'currency' => Currency::EUR,
            'subtotal' => $subtotal,
            'tax' => 0,
            'discount' => 0,
            'total' => $subtotal,
            'customer_id' => null,
            'email' => fake()->safeEmail(),
            'notes' => null,
        ];
    }
}
