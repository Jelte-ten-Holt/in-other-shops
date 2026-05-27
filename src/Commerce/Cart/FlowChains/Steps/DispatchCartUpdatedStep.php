<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\FlowChains\Steps;

use InOtherShops\Commerce\Cart\Events\CartUpdated;
use InOtherShops\Commerce\Cart\FlowChains\AddToCartPayload;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\FlowChain\AbstractFlowStep;
use InOtherShops\FlowChain\Contracts\FlowPayload;

/**
 * Fires the package's CartUpdated event after the cart item is in place.
 *
 * Kept as a standalone step (rather than collapsing into the create step)
 * so consumers can insert their own steps BETWEEN the cart-item write and
 * the event dispatch — the canonical example is attribution capture, which
 * needs the cart_item ID to exist before it writes its row and wants to
 * happen before the event downstream listeners see.
 *
 * @reads cart
 */
final class DispatchCartUpdatedStep extends AbstractFlowStep
{
    public function handle(FlowPayload $payload): void
    {
        assert($payload instanceof AddToCartPayload);

        CartUpdated::dispatch($payload->cart);
    }

    public static function expectedInputs(): array
    {
        return [
            'cart' => Cart::class,
        ];
    }
}
