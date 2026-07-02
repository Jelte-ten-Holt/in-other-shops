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
        $price->update($data->attributes());

        PriceUpdated::dispatch($price);

        return $price;
    }
}
