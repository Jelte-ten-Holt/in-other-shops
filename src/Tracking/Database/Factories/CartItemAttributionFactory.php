<?php

declare(strict_types=1);

namespace InOtherShops\Tracking\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use InOtherShops\Commerce\Commerce;
use InOtherShops\Tracking\Models\CartItemAttribution;
use InOtherShops\Tracking\Tracking;

/**
 * @extends Factory<CartItemAttribution>
 */
final class CartItemAttributionFactory extends Factory
{
    public function modelName(): string
    {
        return Tracking::cartItemAttribution();
    }

    public function definition(): array
    {
        return [
            'cart_item_id' => Commerce::cartItem()::factory(),
            // Null source is the honest default: most adds have no
            // attributable origin. Use ->from() for an attributed row.
            'source_type' => null,
            'source_id' => null,
            'created_at' => now(),
        ];
    }

    /** The surface the add came from — any morph-mapped model. */
    public function from(Model $source): static
    {
        return $this->state(fn (): array => [
            'source_type' => $source->getMorphClass(),
            'source_id' => $source->getKey(),
        ]);
    }
}
