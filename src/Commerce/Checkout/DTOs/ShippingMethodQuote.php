<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Checkout\DTOs;

/**
 * One shipping method's quoted cost and the order total the shopper pays if
 * they pick it. The total is quoted PER METHOD because postage is picked
 * client-side and the total moves with it — the storefront shows the selected
 * method's precomputed total instead of adding cents in the browser.
 *
 * `name` is passed through verbatim from shipping config, which may hold a
 * translation key rather than a label (the config is single-language and a
 * shop may not be) — the consumer runs it through `__()` when rendering.
 */
final readonly class ShippingMethodQuote
{
    public function __construct(
        public string $identifier,
        public string $name,
        public int $cost,
        public int $total,
    ) {}
}
