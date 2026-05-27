<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\FlowChains;

use Illuminate\Database\Eloquent\Model;
use InOtherShops\Commerce\Cart\Contracts\HasCart;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Commerce\Cart\Models\CartItem;
use InOtherShops\FlowChain\Contracts\FlowPayload;

/**
 * Payload for the AddToCart flow chain.
 *
 * Constructor params are the chain's required inputs (declared in
 * AddToCartChain::initialPayloadShape()). The `cartItem` property is
 * written by FindOrCreateCartItemStep and read by downstream steps + the
 * facade.
 */
final class AddToCartPayload implements FlowPayload
{
    public ?CartItem $cartItem = null;

    /**
     * `existingCartItem` is set by EnsureCartableInStockStep so
     * FindOrCreateCartItemStep doesn't have to re-query. Null when the
     * cartable isn't already in the cart.
     */
    public ?CartItem $existingCartItem = null;

    public function __construct(
        public readonly Cart $cart,
        public readonly HasCart&Model $cartable,
        public readonly int $quantity = 1,
    ) {}
}
