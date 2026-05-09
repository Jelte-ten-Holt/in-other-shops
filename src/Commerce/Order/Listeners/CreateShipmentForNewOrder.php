<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Listeners;

use InOtherShops\Commerce\Order\Events\OrderCreated;
use InOtherShops\Shipping\Actions\CreateShipment;
use InOtherShops\Shipping\ShippingConfig;
use RuntimeException;

/**
 * Default OrderCreated handler that creates a single Pending Shipment per
 * Order, covering all of its lines, using the Order's snapshotted shipping
 * method. Disable via `config('shipping.auto_create_shipment') = false`
 * for consumers that compose Shipments themselves (e.g. split warehouse
 * routing).
 *
 * Lives in Commerce rather than Shipping because Commerce is the
 * consumer in the Commerce → Shipping dependency direction.
 */
final class CreateShipmentForNewOrder
{
    public function __construct(
        private readonly CreateShipment $createShipment,
    ) {}

    public function handle(OrderCreated $event): void
    {
        if (! config('shipping.auto_create_shipment', true)) {
            return;
        }

        $identifier = $event->order->shipping_method_identifier;

        if ($identifier === null) {
            return;
        }

        if (ShippingConfig::methods() === []) {
            return;
        }

        $method = ShippingConfig::method($identifier);

        if ($method === null) {
            throw new RuntimeException(
                "Order {$event->order->order_number} has unknown shipping method '{$identifier}'."
            );
        }

        ($this->createShipment)($event->order, $method);
    }
}
