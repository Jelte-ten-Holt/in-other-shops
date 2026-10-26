<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\DTOs;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\DTOs\TaxBreakdownLine;

/**
 * What one shipment carries of its order's value: gross amount in the order's
 * currency and the per-rate breakdown it adds up from.
 */
final readonly class ShipmentValue
{
    /** @param  list<TaxBreakdownLine>  $taxSummary */
    public function __construct(
        public int $amount,
        public Currency $currency,
        public array $taxSummary,
    ) {}
}
