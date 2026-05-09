<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use InOtherShops\Shipping\Enums\ShipmentStatus;
use InOtherShops\Shipping\Models\Shipment;
use InOtherShops\Shipping\Shipping;

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
            'status' => ShipmentStatus::Pending,
        ];
    }

    public function status(ShipmentStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
