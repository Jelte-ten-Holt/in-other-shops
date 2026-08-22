<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\Actions;

use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Pricing\Actions\ResolvePrice;
use InOtherShops\Pricing\Contracts\HasPrices;
use InOtherShops\Pricing\Pricing;

/**
 * Refreshes each cart line's stored `unit_price` to the live resolved price.
 * Cart lines snapshot the price at add time and never refresh it; a price
 * edited (or a strikethrough sale expired by `pricing:expire-compare-at`)
 * between add and checkout would otherwise leave the snapshot quoting a
 * price the order won't charge.
 *
 * THE CADENCE (the price-source contract): the cart snapshot is the single
 * source for the displayed lines, the checkout quote (QuoteCheckout), the
 * voucher dry-run, and the free-shipping threshold. This action is what keeps
 * that snapshot honest — consumers run it:
 *
 *   1. on checkout-form render (the form never shows a price that won't be
 *      charged; surface the returned `true` as a "prices updated" notice),
 *   2. before a voucher apply (so the minimum-order check judges the real
 *      subtotal, not a stale one — the package ApplyVoucherController does
 *      this itself),
 *   3. on submit, bouncing back to the form when anything changed instead of
 *      charging a total the shopper never saw (honour the quoted price: the
 *      narrow window re-quotes, it never silently charges differently).
 *
 * After a submit-time reprice-bounce, snapshot and live prices agree at
 * commit, so quote, lines, threshold, and charge all match by construction.
 *
 * Resolves at the LINE QUANTITY, not quantity 1: totals resolve at line
 * quantity, so a min-quantity price tier would otherwise diverge the same
 * way a raw snapshot does.
 *
 * A line whose price can no longer be resolved (the price row was deleted)
 * is left untouched — zeroing it would let the piece check out free; the
 * unpriced case is the consumer's cart-validation step's to reject, not this
 * action's to paper over.
 */
final class RepriceCart
{
    public function __construct(
        private readonly ResolvePrice $resolvePrice,
    ) {}

    public function __invoke(Cart $cart): bool
    {
        $cart->loadMissing('items.cartable');

        $priceList = Pricing::defaultPriceList();
        $changed = false;

        foreach ($cart->items as $item) {
            $cartable = $item->cartable;

            if (! $cartable instanceof HasPrices) {
                continue;
            }

            $resolved = ($this->resolvePrice)(
                priceable: $cartable,
                currency: $item->effectiveCurrency(),
                quantity: (int) $item->quantity,
                priceList: $priceList,
            )?->amount;

            if ($resolved === null || $resolved === (int) $item->unit_price) {
                continue;
            }

            $item->update(['unit_price' => $resolved]);
            $changed = true;
        }

        return $changed;
    }
}
