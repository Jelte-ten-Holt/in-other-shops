<?php

declare(strict_types=1);

namespace InOtherShops\Pricing;

use InOtherShops\Pricing\Models\Price;
use InOtherShops\Pricing\Models\PriceList;
use InOtherShops\Pricing\Models\Voucher;

final class Pricing
{
    public static function price(): Price
    {
        $class = config('pricing.models.price', Price::class);

        return new $class;
    }

    public static function priceList(): PriceList
    {
        $class = config('pricing.models.price_list', PriceList::class);

        return new $class;
    }

    public static function voucher(): Voucher
    {
        $class = config('pricing.models.voucher', Voucher::class);

        return new $class;
    }
}
