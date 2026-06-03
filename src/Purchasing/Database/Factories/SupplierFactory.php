<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Database\Factories;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Purchasing\Models\Supplier;
use InOtherShops\Purchasing\Purchasing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
final class SupplierFactory extends Factory
{
    public function modelName(): string
    {
        return Purchasing::supplier();
    }

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'contact_email' => fake()->safeEmail(),
            'default_currency' => Currency::EUR,
            'payment_terms' => null,
            'notes' => null,
        ];
    }
}
