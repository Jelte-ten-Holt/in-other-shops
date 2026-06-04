<?php

declare(strict_types=1);

namespace InOtherShops\Commerce;

use InOtherShops\Commerce\Cart\Commands\PruneExpiredCartsCommand;
use InOtherShops\Commerce\Cart\FlowChains\AddToCartChain;
use InOtherShops\Commerce\Listeners\CommerceLogSubscriber;
use InOtherShops\Commerce\Order\Commands\ExpireAbandonedOrdersCommand;
use InOtherShops\Commerce\Order\Events\OrderCreated;
use InOtherShops\Commerce\Order\Events\OrderStatusChanged;
use InOtherShops\Commerce\Order\Listeners\CreateShipmentForNewOrder;
use InOtherShops\Commerce\Order\Listeners\ReconcileRefundFromWebhook;
use InOtherShops\Commerce\Order\Listeners\SyncInventoryOnOrderStatusChange;
use InOtherShops\FlowChain\FlowChainRegistry;
use InOtherShops\Payment\Events\PaymentRefunded;
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
            'refund' => Commerce::refund(),
        ]);

        $this->registerCartRoutes();
        $this->commands([PruneExpiredCartsCommand::class, ExpireAbandonedOrdersCommand::class]);

        Event::subscribe(CommerceLogSubscriber::class);
        Event::listen(OrderCreated::class, CreateShipmentForNewOrder::class);
        Event::listen(OrderStatusChanged::class, SyncInventoryOnOrderStatusChange::class);
        Event::listen(PaymentRefunded::class, ReconcileRefundFromWebhook::class);

        $this->app->make(FlowChainRegistry::class)->register(AddToCartChain::class);
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
