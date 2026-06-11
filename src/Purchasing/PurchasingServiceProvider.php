<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing;

use InOtherShops\Purchasing\Commands\ReconcilePurchaseReceiptsCommand;
use InOtherShops\Purchasing\Listeners\PurchasingLogSubscriber;
use InOtherShops\Support\DomainServiceProvider;

final class PurchasingServiceProvider extends DomainServiceProvider
{
    protected function domainDir(): string
    {
        return __DIR__;
    }

    protected function morphAliases(): array
    {
        return [
            'supplier' => Purchasing::supplier(),
            'purchase_order' => Purchasing::purchaseOrder(),
            'purchase_order_line' => Purchasing::purchaseOrderLine(),
        ];
    }

    protected function logSubscriber(): ?string
    {
        return PurchasingLogSubscriber::class;
    }

    protected function domainCommands(): array
    {
        return [ReconcilePurchaseReceiptsCommand::class];
    }

    protected function publishesConfig(): bool
    {
        return true;
    }
}
