<?php

declare(strict_types=1);

namespace InOtherShops\Currency;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

final class CurrencyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/currency.php', 'currency');
    }

    public function boot(): void
    {
        // Require explicit morph-map aliases across the application so
        // missing aliases fail loudly instead of writing FQCNs into
        // morph columns. Each domain registers its aliases in its own
        // service provider boot(); this call makes the enforcement
        // global.
        Relation::requireMorphMap();

        $this->publishes([
            __DIR__.'/config/currency.php' => config_path('currency.php'),
        ], 'currency-config');
    }
}
