<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Actions;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Contracts\HasPrices;
use InOtherShops\Pricing\Events\PriceCreated;
use InOtherShops\Pricing\Models\Price;
use Illuminate\Database\Eloquent\Model;

final class CreatePrice
{
    public function __invoke(
        Model&HasPrices $priceable,
        int $amount,
        Currency $currency,
        ?int $compareAtAmount = null,
        ?int $priceListId = null,
        int $minimumQuantity = 1,
    ): Price {
        $price = $priceable->prices()->create([
            'amount' => $amount,
            'currency' => $currency,
            'compare_at_amount' => $compareAtAmount,
            'price_list_id' => $priceListId,
            'minimum_quantity' => $minimumQuantity,
        ]);

        PriceCreated::dispatch($price);

        return $price;
    }
}
