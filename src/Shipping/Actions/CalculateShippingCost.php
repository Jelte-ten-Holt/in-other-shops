<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Actions;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Shipping\DTOs\ShippingCost;

final class CalculateShippingCost
{
    public function __invoke(Currency $currency): ShippingCost
    {
        return new ShippingCost(
            amount: (int) config('shipping.flat_rate', 0),
            currency: $currency,
        );
    }
}
