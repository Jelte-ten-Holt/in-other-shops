<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Checkout\DTOs;

use InOtherShops\Currency\Enums\Currency;

/**
 * The pre-order quote: what the checkout form shows before an order exists.
 *
 * Every amount is integer cents in `currency` — formatting is the consumer's
 * (`Currency::format()`), like every other Pricing/Commerce DTO. The quote's
 * arithmetic is the same identity CalculateTotal persists
 * (`total = subtotal − discount + shippingCost`, discount never exceeding
 * subtotal), so what this quotes and what CreateOrder charges cannot diverge
 * in order of operations. Under the inclusive tax mode VAT is contained in the
 * gross and never added on top, which is why a quote needs no tax resolution
 * to be exact — see QuoteCheckout.
 *
 * Voucher state is one of three shapes:
 *  - no code in play: `voucherCode` null, `discount` 0, `droppedVoucherCode` null
 *  - code applied:    `voucherCode` set, `discount` > 0 possible
 *  - code dropped:    `voucherCode` null, `droppedVoucherCode` carries the code
 *    that no longer applies, so the caller can clear it from its checkout state
 *    (see VoucherSession::sync()) and tell the shopper the voucher came off.
 */
final readonly class CheckoutQuote
{
    /**
     * @param  list<ShippingMethodQuote>  $methodQuotes
     */
    public function __construct(
        public int $subtotal,
        public int $discount,
        public int $totalWithoutShipping,
        public Currency $currency,
        public ?string $voucherCode,
        public ?string $droppedVoucherCode,
        public bool $requiresShipping,
        public bool $canShip,
        public array $methodQuotes,
    ) {}
}
