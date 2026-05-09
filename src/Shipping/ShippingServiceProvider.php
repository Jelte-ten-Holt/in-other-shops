<?php

declare(strict_types=1);

namespace InOtherShops\Shipping;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use InOtherShops\Shipping\Listeners\ShipmentLogSubscriber;

final class ShippingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/shipping.php', 'shipping');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        Relation::morphMap([
            'shipment' => Shipping::shipment(),
            'shipment_item' => Shipping::shipmentItem(),
        ]);

        $this->publishes([
            __DIR__.'/config/shipping.php' => config_path('shipping.php'),
        ], 'shipping-config');

        Event::subscribe(ShipmentLogSubscriber::class);
    }
}
