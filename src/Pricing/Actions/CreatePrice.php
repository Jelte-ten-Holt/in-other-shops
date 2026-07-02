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
        $price = $priceable->prices()->create($data->attributes());

        PriceCreated::dispatch($price);

        return $price;
    }
}
