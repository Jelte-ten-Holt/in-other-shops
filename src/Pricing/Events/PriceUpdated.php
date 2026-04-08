<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Events;

use InOtherShops\Pricing\Models\Price;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class PriceUpdated
{
    use Dispatchable;

    public function __construct(
        public Price $price,
    ) {}
}
