<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Actions;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Contracts\HasPrices;
use InOtherShops\Pricing\Models\Price;
use InOtherShops\Pricing\Models\PriceList;

final class ResolvePrice
{
    public function __invoke(
        HasPrices $priceable,
        Currency $currency,
        int $quantity = 1,
        ?PriceList $priceList = null,
    ): ?Price {
        $price = $this->findPrice($priceable, $currency, $quantity, $priceList?->id);

        if ($price === null && $priceList !== null) {
            $price = $this->findPrice($priceable, $currency, $quantity, null);
        }

        return $price;
    }

    private function findPrice(HasPrices $priceable, Currency $currency, int $quantity, ?int $priceListId): ?Price
    {
        return $priceable->prices()
            ->where('currency', $currency->value)
            ->where('price_list_id', $priceListId)
            ->where('minimum_quantity', '<=', $quantity)
            ->orderByDesc('minimum_quantity')
            ->first();
    }
}
