<?php

declare(strict_types=1);

namespace InOtherShops\Storefront\DTOs;

use InOtherShops\Currency\Enums\Currency;

final readonly class StorefrontContext
{
    public function __construct(
        public Currency $currency,
    ) {}
}
