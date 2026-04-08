<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Contracts;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Models\Price;
use InOtherShops\Pricing\Models\PriceList;
use Illuminate\Database\Eloquent\Relations\MorphMany;

interface HasPrices
{
    public function prices(): MorphMany;

    public function priceFor(Currency $currency, ?PriceList $priceList = null): ?Price;

    /**
     * @return array<string> Distinct currency codes from this model's prices.
     */
    public function priceCurrencies(): array;
}
