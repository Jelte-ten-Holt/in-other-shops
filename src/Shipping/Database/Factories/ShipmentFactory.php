<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Database\Factories;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Shipping\Models\Shipment;
use InOtherShops\Shipping\Shipping;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shipment>
 */
final class ShipmentFactory extends Factory
{
    public function modelName(): string
    {
        return Shipping::shipment();
    }

    public function definition(): array
    {
        return [
            'method' => 'standard',
            'cost' => fake()->numberBetween(500, 2000),
            'currency' => Currency::EUR->value,
        ];
    }
}
