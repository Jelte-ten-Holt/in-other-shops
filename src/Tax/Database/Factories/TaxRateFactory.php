<?php

declare(strict_types=1);

namespace InOtherShops\Tax\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use InOtherShops\Tax\Enums\TaxCategory;
use InOtherShops\Tax\Models\TaxRate;
use InOtherShops\Tax\Tax;

/**
 * @extends Factory<TaxRate>
 */
final class TaxRateFactory extends Factory
{
    public function modelName(): string
    {
        return Tax::taxRate();
    }

    public function definition(): array
    {
        return [
            'country_code' => 'NL',
            'tax_category' => null,
            'rate_bps' => 2100,
            'name' => 'Netherlands VAT 21%',
            'is_default' => false,
        ];
    }

    public function default(): self
    {
        return $this->state(['is_default' => true]);
    }

    public function forCountry(string $code, int $rateBps, ?string $name = null): self
    {
        return $this->state([
            'country_code' => strtoupper($code),
            'rate_bps' => $rateBps,
            'name' => $name ?? "{$code} rate",
        ]);
    }

    public function forCategory(TaxCategory $category): self
    {
        return $this->state(['tax_category' => $category->value]);
    }
}
