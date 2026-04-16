<?php

declare(strict_types=1);

namespace InOtherShops\Commerce;

use InOtherShops\Commerce\Cart\Commands\PruneExpiredCartsCommand;
use InOtherShops\Commerce\Listeners\CommerceLogSubscriber;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class CommerceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/commerce.php', 'commerce');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        Relation::morphMap([
            'cart' => Commerce::cart(),
            'cart_item' => Commerce::cartItem(),
            'customer' => Commerce::customer(),
            'customer_group' => Commerce::customerGroup(),
            'order' => Commerce::order(),
            'order_line' => Commerce::orderLine(),
        ]);

        $this->registerCartRoutes();
        $this->commands([PruneExpiredCartsCommand::class]);

        Event::subscribe(CommerceLogSubscriber::class);
    }

    private function registerCartRoutes(): void
    {
        if (! config('commerce.cart.api.enabled', true)) {
            return;
        }

        $prefix = config('commerce.cart.api.prefix', 'api/cart');
        $middleware = config('commerce.cart.api.middleware', ['web']);

        Route::prefix($prefix)
            ->middleware($middleware)
            ->group(__DIR__.'/Cart/Routes/api.php');
    }
}
