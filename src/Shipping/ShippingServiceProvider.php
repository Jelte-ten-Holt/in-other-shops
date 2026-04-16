<?php

declare(strict_types=1);

namespace InOtherShops\Shipping;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

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
        ]);

        $this->publishes([
            __DIR__.'/config/shipping.php' => config_path('shipping.php'),
        ], 'shipping-config');
    }
}
