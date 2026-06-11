<?php

declare(strict_types=1);

namespace InOtherShops\Shipping;

use InOtherShops\Shipping\Listeners\ShipmentLogSubscriber;
use InOtherShops\Support\DomainServiceProvider;

final class ShippingServiceProvider extends DomainServiceProvider
{
    protected function domainDir(): string
    {
        return __DIR__;
    }

    protected function morphAliases(): array
    {
        return [
            'shipment' => Shipping::shipment(),
            'shipment_item' => Shipping::shipmentItem(),
        ];
    }

    protected function logSubscriber(): ?string
    {
        return ShipmentLogSubscriber::class;
    }

    protected function publishesConfig(): bool
    {
        return true;
    }
}
