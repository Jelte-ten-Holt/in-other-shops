<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Events;

use InOtherShops\Pricing\Models\Price;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A strikethrough window closed: the price's compare_at_amount has been
 * promoted to its actual amount and the strikethrough cleared. `$price` is
 * post-promotion; `$previousAmount` is the amount it was sold at during the
 * strikethrough window (otherwise lost on promotion).
 */
final readonly class CompareAtPriceExpired
{
    use Dispatchable;

    public function __construct(
        public Price $price,
        public int $previousAmount,
    ) {}
}
