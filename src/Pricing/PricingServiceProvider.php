<?php

declare(strict_types=1);

namespace InOtherShops\Pricing;

use InOtherShops\Pricing\Listeners\PricingLogSubscriber;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

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

        Event::subscribe(PricingLogSubscriber::class);
    }
}
