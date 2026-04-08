<?php

declare(strict_types=1);

namespace InOtherShops\Commerce;

use Illuminate\Database\Eloquent\Relations\Relation;
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
    }
}
