<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\Http\Controllers;

use InOtherShops\Commerce\Cart\Actions\ClearCart;
use InOtherShops\Commerce\Cart\Http\Resources\CartResource;
use InOtherShops\Commerce\Cart\Http\Support\ResolveCurrentCart;

final class CartController
{
    public function __construct(
        private readonly ResolveCurrentCart $resolveCurrentCart,
    ) {}

    public function show(): CartResource
    {
        $cart = ($this->resolveCurrentCart)();
        $cart->load('items.cartable');

        return new CartResource($cart);
    }

    public function destroy(ClearCart $clearCart): CartResource
    {
        $cart = ($this->resolveCurrentCart)();

        $clearCart($cart);

        return new CartResource($cart->refresh()->load('items.cartable'));
    }
}
