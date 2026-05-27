<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\FlowChains\Steps;

use InOtherShops\Commerce\Cart\Actions\EnsureCartableInStock;
use InOtherShops\Commerce\Cart\Contracts\HasCart;
use InOtherShops\Commerce\Cart\FlowChains\AddToCartPayload;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Commerce\Cart\Models\CartItem;
use InOtherShops\FlowChain\AbstractFlowStep;
use InOtherShops\FlowChain\Contracts\FlowPayload;

/**
 * Computes the running quantity (existing + requested) and asks the
 * EnsureCartableInStock action to refuse if it would oversell. Stashes
 * the existing CartItem on the payload so FindOrCreateCartItemStep
 * doesn't have to re-query for it.
 *
 * @reads cart, cartable, quantity
 * @writes existingCartItem (nullable — null when the cartable isn't yet in the cart)
 */
final class EnsureCartableInStockStep extends AbstractFlowStep
{
    public function __construct(
        private readonly EnsureCartableInStock $ensureCartableInStock,
    ) {}

    public function handle(FlowPayload $payload): void
    {
        assert($payload instanceof AddToCartPayload);

        $existing = $payload->cart->items()
            ->where('cartable_type', $payload->cartable->getMorphClass())
            ->where('cartable_id', $payload->cartable->getKey())
            ->first();

        $runningQuantity = ($existing?->quantity ?? 0) + $payload->quantity;

        ($this->ensureCartableInStock)($payload->cartable, $runningQuantity);

        $payload->existingCartItem = $existing;
    }

    public static function expectedInputs(): array
    {
        return [
            'cart' => Cart::class,
            'cartable' => HasCart::class,
            'quantity' => 'int',
        ];
    }

    public static function producedOutputs(): array
    {
        return [
            'existingCartItem' => '?'.CartItem::class,
        ];
    }
}
