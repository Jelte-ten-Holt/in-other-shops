<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\FlowChains\Steps;

use InOtherShops\Commerce\Cart\Contracts\HasCart;
use InOtherShops\Commerce\Cart\FlowChains\AddToCartPayload;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Commerce\Cart\Models\CartItem;
use InOtherShops\FlowChain\AbstractFlowStep;
use InOtherShops\FlowChain\Contracts\FlowPayload;

/**
 * Reads `existingCartItem` from the payload (set by EnsureCartableInStockStep
 * which already queried for it). If present, increments quantity on the
 * existing row. Otherwise creates a new CartItem with a price + currency
 * snapshot. Writes the resulting CartItem back to the payload.
 *
 * The create path is race-safe (BUG-7): when a concurrent request inserts the
 * same (cart, cartable) line between this chain's pre-read and the insert —
 * the two-tab double-add — the `cart_items` unique key rejects the second
 * insert. `createOrFirst()` converts that unique violation into a fetch of
 * the winner's row (savepoint-wrapped, so the surrounding FlowChain
 * transaction survives on every driver), and the loser takes the increment
 * path instead of escaping as a raw QueryException 500.
 *
 * @reads cart, cartable, quantity, existingCartItem
 * @writes cartItem
 */
final class FindOrCreateCartItemStep extends AbstractFlowStep
{
    public function handle(FlowPayload $payload): void
    {
        assert($payload instanceof AddToCartPayload);

        if ($payload->existingCartItem !== null) {
            $payload->cartItem = $this->incrementQuantity($payload->existingCartItem, $payload->quantity);

            return;
        }

        $currency = $payload->cart->effectiveCurrency();

        $cartItem = $payload->cart->items()->createOrFirst([
            'cartable_type' => $payload->cartable->getMorphClass(),
            'cartable_id' => $payload->cartable->getKey(),
        ], [
            'quantity' => $payload->quantity,
            'unit_price' => $payload->cartable->getCartableUnitPrice($currency),
            'currency' => $currency,
        ]);

        // createOrFirst returned a pre-existing row: we lost the double-add
        // race. Fold this request's quantity into the winner's line (its
        // price snapshot stands, same as the ordinary increment path).
        if (! $cartItem->wasRecentlyCreated) {
            $cartItem = $this->incrementQuantity($cartItem, $payload->quantity);
        }

        $payload->cartItem = $cartItem;
    }

    private function incrementQuantity(CartItem $cartItem, int $quantity): CartItem
    {
        $cartItem->increment('quantity', $quantity);

        return $cartItem->refresh();
    }

    public static function expectedInputs(): array
    {
        return [
            'cart' => Cart::class,
            'cartable' => HasCart::class,
            'quantity' => 'int',
            'existingCartItem' => '?'.CartItem::class,
        ];
    }

    public static function producedOutputs(): array
    {
        return [
            'cartItem' => CartItem::class,
        ];
    }
}
