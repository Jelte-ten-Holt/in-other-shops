<?php

declare(strict_types=1);

namespace InOtherShops\Pricing;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use InOtherShops\Pricing\Commands\ExpireCompareAtPricesCommand;
use InOtherShops\Pricing\Listeners\PricingLogSubscriber;

final class PricingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/pricing.php', 'pricing');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        Relation::morphMap([
            'price' => Pricing::price(),
            'price_list' => Pricing::priceList(),
            'voucher' => Pricing::voucher(),
        ]);

        $this->commands([ExpireCompareAtPricesCommand::class]);

        $this->publishes([
            __DIR__.'/config/pricing.php' => config_path('pricing.php'),
        ], 'pricing-config');

        if (config('pricing.schedule.enabled', true)) {
            $this->app->booted(function () {
                $this->app->make(Schedule::class)
                    ->command('pricing:expire-compare-at')
                    ->hourly();
            });
        }

        Event::subscribe(PricingLogSubscriber::class);
    }
}
