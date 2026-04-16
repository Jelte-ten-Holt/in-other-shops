<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Database\Factories;

use InOtherShops\Inventory\Enums\ReservationStatus;
use InOtherShops\Inventory\Inventory;
use InOtherShops\Inventory\Models\StockReservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockReservation>
 */
final class StockReservationFactory extends Factory
{
    public function modelName(): string
    {
        return Inventory::stockReservation();
    }

    public function definition(): array
    {
        return [
            'quantity' => 1,
            'status' => ReservationStatus::Pending,
            'reserved_until' => now()->addMinutes(30),
            'resolved_at' => null,
            'release_movement_id' => null,
            'description' => null,
            'source' => null,
        ];
    }

    public function expired(): self
    {
        return $this->state(fn () => [
            'status' => ReservationStatus::Pending,
            'reserved_until' => now()->subMinute(),
        ]);
    }

    public function confirmed(): self
    {
        return $this->state(fn () => [
            'status' => ReservationStatus::Confirmed,
            'reserved_until' => null,
            'resolved_at' => now(),
        ]);
    }

    public function released(): self
    {
        return $this->state(fn () => [
            'status' => ReservationStatus::Released,
            'reserved_until' => null,
            'resolved_at' => now(),
        ]);
    }
}
