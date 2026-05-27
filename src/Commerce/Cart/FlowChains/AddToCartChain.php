<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\FlowChains;

use InOtherShops\Commerce\Cart\Contracts\HasCart;
use InOtherShops\Commerce\Cart\FlowChains\Steps\DispatchCartUpdatedStep;
use InOtherShops\Commerce\Cart\FlowChains\Steps\EnsureCartableInStockStep;
use InOtherShops\Commerce\Cart\FlowChains\Steps\FindOrCreateCartItemStep;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\FlowChain\PublishableFlowChain;

/**
 * Publishable orchestration for "add an item to a cart" — the chain
 * consumed by AddToCart::__invoke().
 *
 * # For consumers
 *
 * To customize: run `php artisan flowchain:publish InOtherShops\Commerce\Cart\FlowChains\AddToCartChain`,
 * then edit `app/Project/FlowChains/Cart/AddToCart.php` and override steps()
 * to insert/remove/reorder. Common reasons:
 *
 *   - Attribution capture (insert a step between FindOrCreateCartItemStep
 *     and DispatchCartUpdatedStep)
 *   - Cart-line discounts at add-time
 *   - Custom event dispatch alongside CartUpdated
 *
 * Steps in the default chain reference other package classes by FQN. To
 * modify a step's internals, copy its source from
 * vendor/jelte-ten-holt/in-other-shops/src/Commerce/Cart/FlowChains/Steps/
 * into your project namespace and reference your copy from the published
 * chain.
 */
final class AddToCartChain extends PublishableFlowChain
{
    public static function chainName(): string
    {
        return 'AddToCart';
    }

    public static function domain(): string
    {
        return 'Cart';
    }

    public static function initialPayloadShape(): array
    {
        return [
            'cart' => Cart::class,
            'cartable' => HasCart::class,
            'quantity' => 'int',
            'metadata' => 'array',
        ];
    }

    public static function steps(): array
    {
        return [
            EnsureCartableInStockStep::class,
            FindOrCreateCartItemStep::class,
            DispatchCartUpdatedStep::class,
        ];
    }
}
