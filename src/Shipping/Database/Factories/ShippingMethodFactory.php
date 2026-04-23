<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Shipping\Models\ShippingMethod;
use InOtherShops\Shipping\Shipping;

/**
 * @extends Factory<ShippingMethod>
 */
final class ShippingMethodFactory extends Factory
{
    public function modelName(): string
    {
        return Shipping::shippingMethod();
    }

    public function definition(): array
    {
        static $counter = 0;

        $counter++;

        return [
            'identifier' => 'standard-'.$counter,
            'name' => 'Standard shipping',
            'base_cost' => 595,
            'currency' => Currency::EUR->value,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    public function inactive(): self
    {
        return $this->state(['is_active' => false]);
    }
}
