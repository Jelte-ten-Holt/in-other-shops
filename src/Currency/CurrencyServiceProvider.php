<?php

declare(strict_types=1);

namespace InOtherShops\Currency;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

final class CurrencyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Require explicit morph-map aliases across the application so
        // missing aliases fail loudly instead of writing FQCNs into
        // morph columns. Each domain registers its aliases in its own
        // service provider boot(); this call makes the enforcement
        // global.
        Relation::requireMorphMap();
    }
}
