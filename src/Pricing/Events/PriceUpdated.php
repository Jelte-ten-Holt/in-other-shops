<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Events;

use InOtherShops\Pricing\Models\Price;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The price's amount (or other fields) changed. This is the canonical
 * price-change signal consumers subscribe to for cache invalidation, search
 * reindexing, or denormalized-price refresh — it fires on every amount write,
 * including the scheduled strikethrough promotion (where `fromExpiry` is true
 * and a `CompareAtPriceExpired` is dispatched alongside it). `fromExpiry` lets
 * the package's own log subscriber avoid double-logging the promotion; most
 * consumers can ignore it and treat every PriceUpdated the same.
 */
final readonly class PriceUpdated
{
    use Dispatchable;

    public function __construct(
        public Price $price,
        public bool $fromExpiry = false,
    ) {}
}
