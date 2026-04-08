<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Actions;

use InOtherShops\Pricing\Events\PriceDeleted;
use InOtherShops\Pricing\Models\Price;

final class DeletePrice
{
    public function __invoke(Price $price): void
    {
        $priceId = $price->id;
        $priceableType = $price->priceable_type;
        $priceableId = $price->priceable_id;

        $price->delete();

        PriceDeleted::dispatch($priceId, $priceableType, $priceableId);
    }
}
