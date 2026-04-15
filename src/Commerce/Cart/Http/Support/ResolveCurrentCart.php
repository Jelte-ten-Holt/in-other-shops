<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\Http\Support;

use InOtherShops\Commerce\Cart\Actions\ResolveCart;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Currency\Enums\Currency;
use Illuminate\Support\Facades\Auth;

final class ResolveCurrentCart
{
    public function __construct(
        private readonly ResolveCart $resolveCart,
    ) {}

    public function __invoke(): Cart
    {
        $currency = Currency::from(config('commerce.cart.api.default_currency', 'EUR'));
        $user = Auth::user();

        return ($this->resolveCart)(
            currency: $currency,
            sessionToken: $user ? null : session()->getId(),
            owner: $user,
        );
    }
}
