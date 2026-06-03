<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\DTOs;

/**
 * One rate bracket of a tax breakdown — the shape an invoice / VAT return needs:
 * a taxable base (net), the rate, and the tax on it. VAT is reported per rate,
 * not per line item, so tax is summarised by bracket. All amounts integer cents.
 */
final readonly class TaxBreakdownLine
{
    public function __construct(
        public int $rateBps,
        public int $taxableBase,
        public int $tax,
    ) {}
}
