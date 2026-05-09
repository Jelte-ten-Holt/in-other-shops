<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Events;

use Illuminate\Foundation\Events\Dispatchable;
use InOtherShops\Shipping\Models\Shipment;

final readonly class ShipmentReturnedToSender
{
    use Dispatchable;

    public function __construct(
        public Shipment $shipment,
        public string $reason,
    ) {}
}
