<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use InOtherShops\Inventory\Models\StockHold;

/**
 * A hold row only. What it holds is reservations, which only the hold actions
 * write — so a test that needs units in a hold reserves them through
 * AdjustHold rather than through this factory.
 *
 * @extends Factory<StockHold>
 */
final class StockHoldFactory extends Factory
{
    protected $model = StockHold::class;

    public function definition(): array
    {
        return [
            'name' => 'Markt '.fake()->unique()->numberBetween(1, 99999),
            'held_for' => null,
            'notes' => null,
            'released_at' => null,
        ];
    }

    public function released(): self
    {
        return $this->state(fn (): array => ['released_at' => now()]);
    }
}
