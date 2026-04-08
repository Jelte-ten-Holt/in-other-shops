<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\DTOs;

use InOtherShops\Currency\Enums\Currency;

final readonly class PriceBreakdown
{
    /**
     * @param  array<int, PriceBreakdownLine>  $lines
     */
    public function __construct(
        public int $subtotal,
        public int $discount,
        public int $tax,
        public int $total,
        public Currency $currency,
        public array $lines,
        public ?string $voucherCode = null,
    ) {}
}
