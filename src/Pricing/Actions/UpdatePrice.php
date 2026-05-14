<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Actions;

use InOtherShops\Pricing\DTOs\PriceData;
use InOtherShops\Pricing\Events\PriceUpdated;
use InOtherShops\Pricing\Models\Price;

final class UpdatePrice
{
    public function __invoke(Price $price, PriceData $data): Price
    {
        $price->update([
            'amount' => $data->amount,
            'currency' => $data->currency,
            'compare_at_amount' => $data->compareAtAmount,
            'compare_at_until' => $data->compareAtUntil,
            'price_list_id' => $data->priceListId,
            'minimum_quantity' => $data->minimumQuantity,
        ]);

        PriceUpdated::dispatch($price);

        return $price;
    }
}
