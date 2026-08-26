<?php

declare(strict_types=1);

namespace InOtherShops\Tracking\Contracts;

use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Commerce\Order\Models\Order;

/**
 * Implemented by a consumer's checkout FlowChain payload so
 * SnapshotCartItemAttributions can reach the cart it is snapshotting FROM and
 * the order it is snapshotting ONTO.
 *
 * Why a contract rather than a property read: ProcessCheckout and its payload
 * are app-owned in every consumer (the package owns the pieces of checkout,
 * not the chain), so the package cannot type-hint `App\Actions\Checkout\
 * CheckoutPayload`. This is the same `Has*`-contract seam the package already
 * uses to reach consumer models.
 *
 * Adopting it is two one-line methods on an existing payload — both consumers'
 * payloads already carry exactly these two properties.
 */
interface HasCheckoutAttribution
{
    /** The cart being checked out — the source of the attributions. */
    public function attributionCart(): Cart;

    /**
     * The order created from that cart, or null before the step that creates
     * it has run. The snapshot step no-ops on null rather than guessing.
     */
    public function attributionOrder(): ?Order;
}
