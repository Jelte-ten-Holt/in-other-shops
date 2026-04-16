<?php

declare(strict_types=1);

namespace InOtherShops\Pricing;

use InOtherShops\Pricing\Models\Price;
use InOtherShops\Pricing\Models\PriceList;
use InOtherShops\Pricing\Models\Voucher;

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
}
