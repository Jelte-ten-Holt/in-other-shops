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
use InOtherShops\Support\DomainServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

final class CommerceServiceProvider extends DomainServiceProvider
{
    protected function domainDir(): string
    {
        return __DIR__;
    }

    protected function morphAliases(): array
    {
        return [
            'cart' => Commerce::cart(),
            'cart_item' => Commerce::cartItem(),
            'customer' => Commerce::customer(),
            'customer_group' => Commerce::customerGroup(),
            'order' => Commerce::order(),
            'order_line' => Commerce::orderLine(),
            'refund' => Commerce::refund(),
        ];
    }

    protected function logSubscriber(): ?string
    {
        return CommerceLogSubscriber::class;
    }

    protected function domainCommands(): array
    {
        return [PruneExpiredCartsCommand::class, ExpireAbandonedOrdersCommand::class];
    }

    public function boot(): void
    {
        parent::boot();

        $this->registerCartRoutes();

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
