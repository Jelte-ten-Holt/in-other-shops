<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Database\Factories;

use InOtherShops\Commerce\Commerce;
use InOtherShops\Commerce\Order\Models\OrderLine;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Tax\Enums\TaxCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderLine>
 */
final class OrderLineFactory extends Factory
{
    public function modelName(): string
    {
        return Commerce::orderLine();
    }

    public function definition(): array
    {
        $unit = 1000;
        $qty = 1;

        return [
            'description' => fake()->words(3, true),
            'sku' => null,
            'currency' => Currency::EUR->value,
            'unit_price' => $unit,
            'quantity' => $qty,
            'line_total' => $unit * $qty,
            'tax_category' => TaxCategory::PhysicalGoods->value,
            'tax_rate_bps' => null,
            'tax_amount' => 0,
        ];
    }
}
