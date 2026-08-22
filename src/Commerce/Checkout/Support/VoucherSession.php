<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Checkout\Support;

use InOtherShops\Commerce\Checkout\DTOs\CheckoutQuote;

/**
 * The voucher code a shopper has applied to the checkout they are in the
 * middle of, held in the session between the form and the submit.
 *
 * The session rather than the cart: a voucher is an intent about this
 * checkout, not a property of what is in the basket, and it must not survive
 * into somebody else's session on a shared device. The cart is also shared
 * across tabs and claimed on login, neither of which should carry a code
 * along with it.
 *
 * Only the CODE is stored, never the discount. The amount is re-derived from
 * the live cart on every render (QuoteCheckout), so a code applied against a
 * €90 cart cannot keep its discount after the shopper removes a piece and
 * drops below the voucher's minimum.
 */
final class VoucherSession
{
    public static function code(): ?string
    {
        $code = session(self::key());

        return is_string($code) && $code !== '' ? $code : null;
    }

    public static function remember(string $code): void
    {
        session([self::key() => $code]);
    }

    public static function forget(): void
    {
        session()->forget(self::key());
    }

    /**
     * Reconcile the session with a quote: when QuoteCheckout dropped the held
     * code (expired, spent, under the minimum), forget it so the next render
     * doesn't retry a code that no longer applies. Call this after every
     * render-path quote — it is the blessed sequence's one line, and skipping
     * it re-validates a dead code on every page view.
     */
    public static function sync(CheckoutQuote $quote): void
    {
        if ($quote->droppedVoucherCode !== null && $quote->droppedVoucherCode === self::code()) {
            self::forget();
        }
    }

    private static function key(): string
    {
        return (string) config('commerce.checkout.voucher.session_key', 'checkout.voucher_code');
    }
}
