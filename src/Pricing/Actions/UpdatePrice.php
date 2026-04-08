<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Actions;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Events\PriceUpdated;
use InOtherShops\Pricing\Models\Price;

final class UpdatePrice
{
    public function __invoke(
        Price $price,
        int $amount,
        Currency $currency,
        ?int $compareAtAmount = null,
        ?int $priceListId = null,
        int $minimumQuantity = 1,
    ): Price {
        $price->update([
            'amount' => $amount,
            'currency' => $currency,
            'compare_at_amount' => $compareAtAmount,
            'price_list_id' => $priceListId,
            'minimum_quantity' => $minimumQuantity,
        ]);

        PriceUpdated::dispatch($price);

        return $price;
    }
}
