<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\FlowChains\Steps;

use InOtherShops\Commerce\Cart\Contracts\HasCart;
use InOtherShops\Commerce\Cart\FlowChains\AddToCartPayload;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Commerce\Cart\Models\CartItem;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\FlowChain\AbstractFlowStep;
use InOtherShops\FlowChain\Contracts\FlowPayload;

/**
 * Reads `existingCartItem` from the payload (set by EnsureCartableInStockStep
 * which already queried for it). If present, increments quantity on the
 * existing row. Otherwise creates a new CartItem with a price + currency
 * snapshot. Writes the resulting CartItem back to the payload.
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
            $payload->existingCartItem->increment('quantity', $payload->quantity);
            $payload->cartItem = $payload->existingCartItem->refresh();

            return;
        }

        $currency = $this->resolveCurrency($payload->cart);

        $payload->cartItem = $payload->cart->items()->create([
            'cartable_type' => $payload->cartable->getMorphClass(),
            'cartable_id' => $payload->cartable->getKey(),
            'quantity' => $payload->quantity,
            'unit_price' => $payload->cartable->getCartableUnitPrice($currency),
            'currency' => $currency,
        ]);
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

    private function resolveCurrency(Cart $cart): Currency
    {
        if ($cart->currency instanceof Currency) {
            return $cart->currency;
        }

        return Currency::from(config('commerce.cart.api.default_currency', 'EUR'));
    }
}
