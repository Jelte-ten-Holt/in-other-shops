<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\DTOs;

use DateTimeInterface;
use InOtherShops\Currency\Enums\Currency;

/**
 * Input shape shared by {@see \InOtherShops\Pricing\Actions\CreatePrice} and
 * {@see \InOtherShops\Pricing\Actions\UpdatePrice}. Prices are high-churn —
 * new fields land here once, not on every action signature and callsite.
 */
final readonly class PriceData
{
    public function __construct(
        public int $amount,
        public Currency $currency,
        public ?int $compareAtAmount = null,
        public ?DateTimeInterface $compareAtUntil = null,
        public ?int $priceListId = null,
        public int $minimumQuantity = 1,
    ) {}
}
