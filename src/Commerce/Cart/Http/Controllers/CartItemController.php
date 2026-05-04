<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\Http\Controllers;

use DomainException;
use Illuminate\Http\JsonResponse;
use InOtherShops\Commerce\Cart\Actions\AddToCart;
use InOtherShops\Commerce\Cart\Actions\RemoveFromCart;
use InOtherShops\Commerce\Cart\Actions\UpdateCartItemQuantity;
use InOtherShops\Commerce\Cart\Http\Requests\AddToCartRequest;
use InOtherShops\Commerce\Cart\Http\Requests\UpdateCartItemRequest;
use InOtherShops\Commerce\Cart\Http\Resources\CartResource;
use InOtherShops\Commerce\Cart\Http\Support\ResolveCurrentCart;
use InOtherShops\Commerce\Cart\Models\CartItem;

final class CartItemController
{
    public function __construct(
        private readonly ResolveCurrentCart $resolveCurrentCart,
    ) {}

    public function store(AddToCartRequest $request, AddToCart $addToCart): CartResource|JsonResponse
    {
        $cart = ($this->resolveCurrentCart)();

        try {
            $addToCart($cart, $request->cartable(), $request->quantity());
        } catch (DomainException $e) {
            return $this->cartRuleViolation($e);
        }

        return new CartResource($cart->refresh()->load('items.cartable'));
    }

    public function update(
        UpdateCartItemRequest $request,
        CartItem $cartItem,
        UpdateCartItemQuantity $updateQuantity,
    ): CartResource|JsonResponse {
        $this->ensureItemBelongsToCurrentCart($cartItem);

        try {
            $updateQuantity($cartItem, $request->quantity());
        } catch (DomainException $e) {
            return $this->cartRuleViolation($e);
        }

        $cart = ($this->resolveCurrentCart)();

        return new CartResource($cart->refresh()->load('items.cartable'));
    }

    public function destroy(CartItem $cartItem, RemoveFromCart $removeFromCart): CartResource
    {
        $this->ensureItemBelongsToCurrentCart($cartItem);

        $removeFromCart($cartItem);

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

    private function cartRuleViolation(DomainException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'errors' => ['cart' => [$e->getMessage()]],
        ], 422);
    }
}
