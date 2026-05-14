<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Database\Factories;

use DateTimeInterface;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Models\Price;
use InOtherShops\Pricing\Pricing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Price>
 */
final class PriceFactory extends Factory
{
    public function modelName(): string
    {
        return Pricing::price();
    }

    public function definition(): array
    {
        return [
            'currency' => Currency::EUR->value,
            'amount' => 1000,
            'compare_at_amount' => null,
            'compare_at_until' => null,
            'minimum_quantity' => 1,
        ];
    }

    /**
     * A price with an active strikethrough. `compare_at_amount` defaults to
     * double the amount so it satisfies the model invariant; pass `$until` to
     * give it an expiry window.
     */
    public function onSale(?int $compareAtAmount = null, ?DateTimeInterface $until = null): self
    {
        return $this->state(fn (array $attributes): array => [
            'compare_at_amount' => $compareAtAmount ?? ((int) $attributes['amount'] * 2),
            'compare_at_until' => $until,
        ]);
    }
}
