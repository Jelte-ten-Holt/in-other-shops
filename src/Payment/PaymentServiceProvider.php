<?php

declare(strict_types=1);

namespace InOtherShops\Payment;

use InOtherShops\Payment\Commands\PrunePaymentWebhookEventsCommand;
use InOtherShops\Payment\Listeners\PaymentLogSubscriber;
use InOtherShops\Support\DomainServiceProvider;

final class PaymentServiceProvider extends DomainServiceProvider
{
    protected function domainDir(): string
    {
        return __DIR__;
    }

    protected function morphAliases(): array
    {
        return [
            'payment' => Payment::payment(),
            'payment_profile' => Payment::paymentProfile(),
        ];
    }

    protected function logSubscriber(): ?string
    {
        return PaymentLogSubscriber::class;
    }

    protected function domainCommands(): array
    {
        return [PrunePaymentWebhookEventsCommand::class];
    }

    protected function publishesConfig(): bool
    {
        return true;
    }

    public function register(): void
    {
        parent::register();

        // The one bespoke binding this domain needs beyond the base
        // register/boot: the gateway manager singleton.
        $this->app->singleton(PaymentGatewayManager::class);
    }
}
