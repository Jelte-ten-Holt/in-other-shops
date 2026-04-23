<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\DTOs;

final readonly class ShippingSnapshot
{
    public function __construct(
        public string $methodIdentifier,
        public int $cost,
        public string $currency,
    ) {}
}
