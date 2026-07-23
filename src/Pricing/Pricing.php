<?php

declare(strict_types=1);

namespace InOtherShops\Pricing;

use InOtherShops\Pricing\Models\Price;
use InOtherShops\Pricing\Models\PriceList;
use InOtherShops\Pricing\Models\Voucher;
use InOtherShops\Pricing\Support\DefaultPriceListResolver;

final class Pricing
{
    /** @return class-string<Price> */
    public static function price(): string
    {
        return config('pricing.models.price', Price::class);
    }

    /** @return class-string<PriceList> */
    public static function priceList(): string
    {
        return config('pricing.models.price_list', PriceList::class);
    }

    /** @return class-string<Voucher> */
    public static function voucher(): string
    {
        return config('pricing.models.voucher', Voucher::class);
    }

    /**
     * The default price list (`is_default = true`), resolved at most once per
     * request/container scope — repeat calls within a request issue no query,
     * including when no default exists. See DefaultPriceListResolver.
     */
    public static function defaultPriceList(): ?PriceList
    {
        return app(DefaultPriceListResolver::class)->resolve();
    }

    /** Clear the memoized default so the next call re-resolves (current scope only). */
    public static function forgetDefaultPriceList(): void
    {
        app(DefaultPriceListResolver::class)->forget();
    }
}
