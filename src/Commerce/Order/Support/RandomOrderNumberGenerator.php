<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Support;

use InOtherShops\Commerce\Order\Contracts\OrderNumberGenerator;
use Illuminate\Support\Str;

/**
 * Default generator: `{prefix}-{8 chars random}` (e.g. `ORD-A3F2K9B1`).
 *
 * The `orders.order_number` unique index is the ultimate guard — swap in
 * a sequential generator via `commerce.order.number_generator` when you
 * need human-friendly sequences.
 */
final class RandomOrderNumberGenerator implements OrderNumberGenerator
{
    public function __invoke(): string
    {
        $prefix = config('commerce.order.number_prefix', 'ORD');

        return $prefix.'-'.strtoupper(Str::random(8));
    }
}
