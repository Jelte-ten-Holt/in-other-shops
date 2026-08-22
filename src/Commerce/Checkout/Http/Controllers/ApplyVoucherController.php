<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Checkout\Http\Controllers;

use InOtherShops\Commerce\Cart\Actions\RepriceCart;
use InOtherShops\Commerce\Cart\Http\Support\ResolveCurrentCart;
use InOtherShops\Commerce\Checkout\Actions\QuoteCheckout;
use InOtherShops\Commerce\Checkout\Http\Requests\ApplyVoucherRequest;
use InOtherShops\Commerce\Checkout\Support\VoucherSession;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Exceptions\PricingException;
use InOtherShops\Pricing\Exceptions\VoucherMinimumNotMetException;
use InOtherShops\Pricing\Exceptions\VoucherNotFoundException;
use InOtherShops\Pricing\Models\Voucher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Applies a voucher code to the checkout in progress, so the shopper sees the
 * discount before they pay rather than after.
 *
 * Only the CODE is stored (see VoucherSession) — the discount is re-derived
 * on every render of the form, because the cart underneath it can still
 * change. The cart is repriced before the dry-run, so the voucher's
 * minimum-order check judges the real subtotal and not a stale snapshot.
 *
 * RATE LIMITED, because this endpoint answers "is this a real code?" and
 * nothing else stops a shopper — or a script — walking the code space until
 * something lands. Keyed on IP rather than session id: a session cookie is
 * cleared in a keystroke, which would make the limit decorative. The IP is
 * only as honest as the consumer's proxy trust — see the periphery doc's
 * voucher-throttle note for both failure directions (untrusted proxy: every
 * shopper shares one bucket; over-trusted forwarded headers: spoofed IPs
 * defeat the limit).
 */
final class ApplyVoucherController
{
    public function __construct(
        private readonly ResolveCurrentCart $resolveCurrentCart,
        private readonly RepriceCart $repriceCart,
        private readonly QuoteCheckout $quoteCheckout,
    ) {}

    public function __invoke(ApplyVoucherRequest $request): RedirectResponse
    {
        $limiterKey = 'checkout-voucher:'.$request->ip();

        if (RateLimiter::tooManyAttempts($limiterKey, $this->maxAttempts())) {
            return $this->failed('shops-commerce::checkout.voucher.errors.throttled', [
                'seconds' => RateLimiter::availableIn($limiterKey),
            ]);
        }

        RateLimiter::hit($limiterKey, $this->decaySeconds());

        $code = $request->voucherCode();
        $cart = ($this->resolveCurrentCart)();

        ($this->repriceCart)($cart);

        try {
            $quote = ($this->quoteCheckout)($cart, $code, throwOnInvalidVoucher: true);
        } catch (PricingException $exception) {
            return $this->failed(...$this->rejection($exception, $code, $cart->effectiveCurrency()));
        }

        // Clear the budget on success: the attempts were spent proving the
        // shopper had a real code, and they now have one. Only guessing costs.
        RateLimiter::clear($limiterKey);

        VoucherSession::remember($quote->voucherCode ?? $code);

        return redirect()->route($this->redirectRoute());
    }

    /**
     * Why the code was refused, as a translation key + params. Deliberately
     * specific rather than one blanket "invalid code": an expired code and a
     * code that needs a bigger order call for different things from the
     * shopper, and a shopper who can't tell them apart just retypes the same
     * code. It does confirm to a guesser which codes exist — that is what the
     * rate limit is for, and it is the cheaper side of the trade at this
     * scale. Translated server-side (the storefront renders the error bag
     * verbatim); consumers override wording via Laravel's vendor lang
     * overrides (`lang/vendor/shops-commerce/{locale}/checkout.php`).
     *
     * @return array{string, array<string, string>}
     */
    private function rejection(PricingException $exception, string $code, Currency $currency): array
    {
        return match (true) {
            $exception instanceof VoucherNotFoundException => ['shops-commerce::checkout.voucher.errors.not_found', []],
            $exception instanceof VoucherMinimumNotMetException => [
                'shops-commerce::checkout.voucher.errors.minimum',
                ['amount' => $currency->format($this->minimumFor($code))],
            ],
            // Expired, spent, or (single-currency shop) a currency mismatch —
            // all "this code is not usable", nothing the shopper can act on.
            default => ['shops-commerce::checkout.voucher.errors.invalid', []],
        };
    }

    private function minimumFor(string $code): int
    {
        return (int) Voucher::query()
            ->where('code', Voucher::normalizeCode($code))
            ->value('minimum_order_amount');
    }

    /** @param array<string, string|int> $replace */
    private function failed(string $key, array $replace = []): RedirectResponse
    {
        return redirect()->route($this->redirectRoute())
            ->withErrors(['voucher_code' => __($key, $replace)]);
    }

    private function redirectRoute(): string
    {
        return (string) config('commerce.checkout.voucher.redirect_route', 'checkout.index');
    }

    private function maxAttempts(): int
    {
        return (int) config('commerce.checkout.voucher.rate_limit.max_attempts', 5);
    }

    private function decaySeconds(): int
    {
        return (int) config('commerce.checkout.voucher.rate_limit.decay_seconds', 60);
    }
}
