<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\DTOs;

use InOtherShops\Currency\Enums\Currency;

final readonly class ShippingCost
{
    public function __construct(
        public int $amount,
        public Currency $currency,
    ) {}
}
