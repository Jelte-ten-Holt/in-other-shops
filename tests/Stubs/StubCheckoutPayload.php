<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs;

use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\FlowChain\Contracts\FlowPayload;
use InOtherShops\Tracking\Contracts\HasCheckoutAttribution;

/**
 * Stands in for a consumer's checkout FlowChain payload. Checkout chains are
 * app-owned in every consumer, so the package's own suite needs its own minimal
 * payload to exercise SnapshotCartItemAttributions against the contract rather
 * than against any one consumer's class.
 */
final class StubCheckoutPayload implements FlowPayload, HasCheckoutAttribution
{
    public function __construct(
        public readonly Cart $cart,
        public ?Order $order = null,
    ) {}

    public function attributionCart(): Cart
    {
        return $this->cart;
    }

    public function attributionOrder(): ?Order
    {
        return $this->order;
    }
}
