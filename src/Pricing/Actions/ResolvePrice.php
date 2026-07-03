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
        // When the caller already eager-loaded `prices` (the catalogue does
        // `with('prices')` for exactly this), resolve in-memory instead of
        // issuing a fresh query per priceable (SCALE-2). Falls back to a scoped
        // query when the relation isn't loaded, so a single lookup stays cheap.
        if ($priceable->relationLoaded('prices')) {
            return $this->pickFromLoaded($priceable->prices, $currency, $quantity, $priceListId);
        }

        return $priceable->prices()
            ->where('currency', $currency->value)
            ->where('price_list_id', $priceListId)
            ->where('minimum_quantity', '<=', $quantity)
            ->orderByDesc('minimum_quantity')
            ->first();
    }

    /**
     * In-memory equivalent of the scoped query above, matching its filters and
     * `orderByDesc('minimum_quantity')` tie-break so a loaded-relation resolve
     * returns the same Price the query would.
     *
     * @param  \Illuminate\Support\Collection<int, Price>  $prices
     */
    private function pickFromLoaded(iterable $prices, Currency $currency, int $quantity, ?int $priceListId): ?Price
    {
        return collect($prices)
            ->filter(fn (Price $price): bool => $price->currency === $currency
                && $this->matchesPriceList($price, $priceListId)
                && (int) $price->minimum_quantity <= $quantity)
            ->sortByDesc('minimum_quantity')
            ->first();
    }

    private function matchesPriceList(Price $price, ?int $priceListId): bool
    {
        $rowPriceListId = $price->price_list_id;

        if ($priceListId === null) {
            return $rowPriceListId === null;
        }

        return $rowPriceListId !== null && (int) $rowPriceListId === $priceListId;
    }
}
