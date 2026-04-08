<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class PriceDeleted
{
    use Dispatchable;

    public function __construct(
        public int $priceId,
        public string $priceableType,
        public int $priceableId,
    ) {}
}
