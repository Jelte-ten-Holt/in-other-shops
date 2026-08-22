<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Checkout\Http;

use InOtherShops\Commerce\Checkout\Http\Controllers\ApplyVoucherController;
use InOtherShops\Commerce\Checkout\Http\Controllers\RemoveVoucherController;
use Illuminate\Support\Facades\Route;

/**
 * Route registrar the CONSUMER mounts — the package never auto-registers
 * these. Both known consumers localize their URLs inside a `{locale}` route
 * group, and a package-registered route outside that group would 500 on
 * `route()` generation (the redirect targets a locale-parameterized route)
 * while carrying its own untouched rate-limit bucket. One mode, no enabled
 * flag to invert: the consumer calls this inside its own localized group,
 * choosing the path segment its shop speaks.
 *
 *     Route::prefix('{locale}')->middleware(SetLocale::class)->group(function () {
 *         CheckoutRoutes::voucher('checkout/descuento');
 *     });
 */
final class CheckoutRoutes
{
    /**
     * The voucher apply/remove pair. Route names are fixed — the storefront
     * posts to `checkout.voucher.apply` / `checkout.voucher.remove` and the
     * controllers redirect to `commerce.checkout.voucher.redirect_route`
     * (default `checkout.index`).
     */
    public static function voucher(string $path = 'checkout/voucher'): void
    {
        Route::post($path, ApplyVoucherController::class)->name('checkout.voucher.apply');
        Route::delete($path, RemoveVoucherController::class)->name('checkout.voucher.remove');
    }
}
