<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Actions;

use Illuminate\Database\Eloquent\Model;
use InOtherShops\Pricing\Contracts\HasPrices;
use InOtherShops\Pricing\DTOs\PriceData;
use InOtherShops\Pricing\Events\PriceCreated;
use InOtherShops\Pricing\Models\Price;

final class CreatePrice
{
    public function __invoke(Model&HasPrices $priceable, PriceData $data): Price
    {
        $price = $priceable->prices()->create([
            'amount' => $data->amount,
            'currency' => $data->currency,
            'compare_at_amount' => $data->compareAtAmount,
            'compare_at_until' => $data->compareAtUntil,
            'price_list_id' => $data->priceListId,
            'minimum_quantity' => $data->minimumQuantity,
        ]);

        PriceCreated::dispatch($price);

        return $price;
    }
}
