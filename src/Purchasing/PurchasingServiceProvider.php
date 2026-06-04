<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing;

use InOtherShops\Purchasing\Commands\ReconcilePurchaseReceiptsCommand;
use InOtherShops\Purchasing\Listeners\PurchasingLogSubscriber;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class PurchasingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/purchasing.php', 'purchasing');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        Relation::morphMap([
            'supplier' => Purchasing::supplier(),
            'purchase_order' => Purchasing::purchaseOrder(),
            'purchase_order_line' => Purchasing::purchaseOrderLine(),
        ]);

        $this->publishes([
            __DIR__.'/config/purchasing.php' => config_path('purchasing.php'),
        ], 'purchasing-config');

        Event::subscribe(PurchasingLogSubscriber::class);

        $this->commands([ReconcilePurchaseReceiptsCommand::class]);
    }
}
