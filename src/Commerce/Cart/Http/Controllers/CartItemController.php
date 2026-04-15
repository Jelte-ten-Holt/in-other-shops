<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\Http\Controllers;

use InOtherShops\Commerce\Cart\Actions\AddToCart;
use InOtherShops\Commerce\Cart\Actions\RemoveFromCart;
use InOtherShops\Commerce\Cart\Actions\UpdateCartItemQuantity;
use InOtherShops\Commerce\Cart\Http\Requests\AddToCartRequest;
use InOtherShops\Commerce\Cart\Http\Requests\UpdateCartItemRequest;
use InOtherShops\Commerce\Cart\Http\Resources\CartResource;
use InOtherShops\Commerce\Cart\Http\Support\ResolveCurrentCart;
use InOtherShops\Commerce\Cart\Models\CartItem;
use InOtherShops\Commerce\Commerce;

final class CartItemController
{
    public function __construct(
        private readonly ResolveCurrentCart $resolveCurrentCart,
    ) {}

    public function store(AddToCartRequest $request, AddToCart $addToCart): CartResource
    {
        $cart = ($this->resolveCurrentCart)();

        $addToCart($cart, $request->cartable(), $request->quantity());

        return new CartResource($cart->refresh()->load('items.cartable'));
    }

    public function update(
        UpdateCartItemRequest $request,
        CartItem $item,
        UpdateCartItemQuantity $updateQuantity,
    ): CartResource {
        $this->ensureItemBelongsToCurrentCart($item);

        $updateQuantity($item, $request->quantity());

        $cart = ($this->resolveCurrentCart)();

        return new CartResource($cart->refresh()->load('items.cartable'));
    }

    public function destroy(CartItem $item, RemoveFromCart $removeFromCart): CartResource
    {
        $this->ensureItemBelongsToCurrentCart($item);

        $removeFromCart($item);

        $cart = ($this->resolveCurrentCart)();

        return new CartResource($cart->refresh()->load('items.cartable'));
    }

    private function ensureItemBelongsToCurrentCart(CartItem $item): void
    {
        $cart = ($this->resolveCurrentCart)();

        if ($item->cart_id !== $cart->id) {
            abort(404);
        }
    }
}
