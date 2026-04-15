<?php

declare(strict_types=1);

namespace InOtherShops\Commerce;

use Illuminate\Database\Eloquent\Relations\Relation;
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
            'cart' => Commerce::cart()::class,
            'cart_item' => Commerce::cartItem()::class,
            'customer' => Commerce::customer()::class,
            'customer_group' => Commerce::customerGroup()::class,
            'order' => Commerce::order()::class,
            'order_line' => Commerce::orderLine()::class,
        ]);

        $this->registerCartRoutes();
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
