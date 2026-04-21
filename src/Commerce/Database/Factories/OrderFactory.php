<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use InOtherShops\Commerce\Commerce;
use InOtherShops\Commerce\Customer\Models\Customer;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Location\Enums\AddressType;
use InOtherShops\Location\Location;

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

    public function status(OrderStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    public function forCustomer(?Customer $customer = null): static
    {
        return $this->state(fn (): array => [
            'customer_id' => $customer?->id ?? Commerce::customer()::factory(),
        ]);
    }

    public function withAddresses(): static
    {
        return $this->afterCreating(function (Order $order): void {
            Location::address()::factory()
                ->for($order, 'addressable')
                ->state(['type' => AddressType::Shipping])
                ->create();

            Location::address()::factory()
                ->for($order, 'addressable')
                ->state(['type' => AddressType::Billing])
                ->create();
        });
    }

    public function withLines(int $count = 3): static
    {
        return $this->afterCreating(function (Order $order) use ($count): void {
            $subtotal = 0;

            for ($i = 0; $i < $count; $i++) {
                $unitPrice = fake()->numberBetween(500, 15000);
                $quantity = fake()->numberBetween(1, 5);
                $lineTotal = $unitPrice * $quantity;
                $subtotal += $lineTotal;

                $order->lines()->create([
                    'description' => fake()->words(fake()->numberBetween(2, 4), true),
                    'sku' => strtoupper(fake()->bothify('??-####')),
                    'currency' => $order->currency->value,
                    'unit_price' => $unitPrice,
                    'quantity' => $quantity,
                    'line_total' => $lineTotal,
                ]);
            }

            $tax = (int) round($subtotal * 0.21);
            $order->update([
                'subtotal' => $subtotal,
                'tax' => $tax,
                'total' => $subtotal + $tax - $order->discount,
            ]);
        });
    }
}
