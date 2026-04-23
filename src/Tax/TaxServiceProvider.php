<?php

declare(strict_types=1);

namespace InOtherShops\Tax;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

final class TaxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/tax.php', 'tax');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        Relation::morphMap([
            'tax_rate' => Tax::taxRate(),
        ]);

        $this->publishes([
            __DIR__.'/config/tax.php' => config_path('tax.php'),
        ], 'tax-config');
    }
}
