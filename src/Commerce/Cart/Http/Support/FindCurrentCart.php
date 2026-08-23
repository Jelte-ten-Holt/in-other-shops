<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\Http\Support;

use InOtherShops\Commerce\Cart\Actions\FindCart;
use InOtherShops\Commerce\Cart\Models\Cart;
use Illuminate\Support\Facades\Auth;

/**
 * The read-only twin of {@see ResolveCurrentCart}. Returns the current cart, or
 * null when the visitor has never had one — without writing a row.
 *
 * Use this anywhere the cart is being *displayed* (badges, shared Inertia props,
 * summaries). Use ResolveCurrentCart only where the request genuinely creates
 * cart state.
 */
final class FindCurrentCart
{
    public function __construct(
        private readonly FindCart $findCart,
    ) {}

    public function __invoke(): ?Cart
    {
        $user = Auth::user();

        return ($this->findCart)(
            sessionToken: $user ? null : session()->getId(),
            owner: $user,
        );
    }
}
