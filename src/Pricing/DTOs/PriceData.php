<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\DTOs;

use DateTimeInterface;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Exceptions\InvalidCompareAtPriceException;

/**
 * Input shape shared by {@see \InOtherShops\Pricing\Actions\CreatePrice} and
 * {@see \InOtherShops\Pricing\Actions\UpdatePrice}. Prices are high-churn —
 * new fields land here once, not on every action signature and callsite.
 *
 * The compare-at invariant is enforced here so every write path that builds a
 * PriceData — the actions and, through them, the Filament admin — rejects an
 * orphan or already-expired strikethrough at construction. The Filament form
 * also blocks it via `->after('now')`, but imports, agent tools, and other
 * programmatic callers only have this guard. Direct model writes (the expiry
 * sweep, factories, seeders) bypass it deliberately — they need to construct
 * already-elapsed states. See audit findings A-3 and A-5.
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
    ) {
        if ($this->compareAtUntil !== null && $this->compareAtAmount === null) {
            throw InvalidCompareAtPriceException::endDateWithoutAmount();
        }

        if ($this->compareAtUntil !== null && $this->compareAtUntil->getTimestamp() <= now()->getTimestamp()) {
            throw InvalidCompareAtPriceException::endDateInPast($this->compareAtUntil);
        }
    }
}
