<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\DTOs;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Enums\TaxMode;

final readonly class PriceBreakdown
{
    /**
     * `subtotal` is the gross (tax-inclusive) sum of line totals under the
     * inclusive tax mode; `tax` is the included tax (a component of the gross),
     * summed from `taxBreakdown`. `total = subtotal − discount + shippingCost`.
     *
     * @param  array<int, PriceBreakdownLine>  $lines
     * @param  array<int, TaxBreakdownLine>  $taxBreakdown  one entry per rate bracket
     */
    public function __construct(
        public int $subtotal,
        public int $discount,
        public int $tax,
        public int $shippingCost,
        public int $total,
        public Currency $currency,
        public array $lines,
        public array $taxBreakdown = [],
        public TaxMode $taxMode = TaxMode::Inclusive,
        public ?string $voucherCode = null,
    ) {}
}
