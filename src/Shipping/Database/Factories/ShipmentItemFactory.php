<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use InOtherShops\Shipping\Models\ShipmentItem;
use InOtherShops\Shipping\Shipping;

/**
 * @extends Factory<ShipmentItem>
 */
final class ShipmentItemFactory extends Factory
{
    public function modelName(): string
    {
        return Shipping::shipmentItem();
    }

    public function definition(): array
    {
        return [
            'quantity' => 1,
        ];
    }
}
