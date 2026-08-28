<?php

declare(strict_types=1);

namespace InOtherShops\Tracking\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use InOtherShops\Commerce\Commerce;
use InOtherShops\Tracking\Models\OrderLineAttribution;
use InOtherShops\Tracking\Tracking;

/**
 * @extends Factory<OrderLineAttribution>
 */
final class OrderLineAttributionFactory extends Factory
{
    public function modelName(): string
    {
        return Tracking::orderLineAttribution();
    }

    public function definition(): array
    {
        return [
            'order_line_id' => Commerce::orderLine()::factory(),
            'source_type' => null,
            'source_id' => null,
            'created_at' => now(),
        ];
    }

    /** The surface the original cart add came from — any morph-mapped model. */
    public function from(Model $source): static
    {
        return $this->state(fn (): array => [
            'source_type' => $source->getMorphClass(),
            'source_id' => $source->getKey(),
        ]);
    }
}
