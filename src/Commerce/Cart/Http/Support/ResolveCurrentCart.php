<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\Http\Support;

use InOtherShops\Commerce\Cart\Actions\ResolveCart;
use InOtherShops\Commerce\Cart\Models\Cart;
use Illuminate\Support\Facades\Auth;

final class ResolveCurrentCart
{
    public function __construct(
        private readonly ResolveCart $resolveCart,
    ) {}

    public function __invoke(): Cart
    {
        $currency = Cart::defaultCurrency();
        $user = Auth::user();

        return ($this->resolveCart)(
            currency: $currency,
            sessionToken: $user ? null : session()->getId(),
            owner: $user,
        );
    }
}
