<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\DTOs;

final readonly class TaxSnapshot
{
    public function __construct(
        public int $rateBps,
        public string $countryCode,
    ) {}
}
