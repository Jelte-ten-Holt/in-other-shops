<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Checkout\Http\Controllers;

use InOtherShops\Commerce\Checkout\Support\VoucherSession;
use Illuminate\Http\RedirectResponse;

/**
 * Takes the applied voucher back off the checkout. Not rate limited —
 * removing a code reveals nothing about which codes exist.
 */
final class RemoveVoucherController
{
    public function __invoke(): RedirectResponse
    {
        VoucherSession::forget();

        return redirect()->route(
            (string) config('commerce.checkout.voucher.redirect_route', 'checkout.index'),
        );
    }
}
