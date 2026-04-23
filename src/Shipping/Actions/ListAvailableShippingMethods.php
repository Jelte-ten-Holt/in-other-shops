<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Actions;

use Illuminate\Support\Collection;
use InOtherShops\Location\Models\Address;
use InOtherShops\Shipping\Shipping;

final class ListAvailableShippingMethods
{
    public function __invoke(?Address $address = null): Collection
    {
        $model = Shipping::shippingMethod();

        return $model::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }
}
