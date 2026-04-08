<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Concerns;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Actions\ResolvePrice;
use InOtherShops\Pricing\Models\Price;
use InOtherShops\Pricing\Models\PriceList;
use InOtherShops\Pricing\Pricing;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait InteractsWithPrices
{
    public function prices(): MorphMany
    {
        $model = Pricing::price();

        return $this->morphMany($model::class, 'priceable');
    }

    public function priceFor(Currency $currency, ?PriceList $priceList = null): ?Price
    {
        return app(ResolvePrice::class)(
            priceable: $this,
            currency: $currency,
            priceList: $priceList,
        );
    }

    /**
     * @return array<string>
     */
    public function priceCurrencies(): array
    {
        return $this->prices()
            ->distinct()
            ->pluck('currency')
            ->map(fn (Currency $currency): string => $currency->value)
            ->all();
    }
}
