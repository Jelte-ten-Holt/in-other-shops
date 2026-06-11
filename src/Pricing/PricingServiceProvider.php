<?php

declare(strict_types=1);

namespace InOtherShops\Pricing;

use Illuminate\Console\Scheduling\Schedule;
use InOtherShops\Pricing\Commands\ExpireCompareAtPricesCommand;
use InOtherShops\Pricing\Listeners\PricingLogSubscriber;
use InOtherShops\Support\DomainServiceProvider;

final class PricingServiceProvider extends DomainServiceProvider
{
    protected function domainDir(): string
    {
        return __DIR__;
    }

    protected function morphAliases(): array
    {
        return [
            'price' => Pricing::price(),
            'price_list' => Pricing::priceList(),
            'voucher' => Pricing::voucher(),
        ];
    }

    protected function logSubscriber(): ?string
    {
        return PricingLogSubscriber::class;
    }

    protected function domainCommands(): array
    {
        return [ExpireCompareAtPricesCommand::class];
    }

    protected function publishesConfig(): bool
    {
        return true;
    }

    public function boot(): void
    {
        parent::boot();

        $this->scheduleWhenEnabled(function (Schedule $schedule): void {
            $schedule->command('pricing:expire-compare-at')->hourly();
        });
    }
}
