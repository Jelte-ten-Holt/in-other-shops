<?php

declare(strict_types=1);

namespace InOtherShops\Variants;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

final class VariantsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/variants.php', 'variants');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        Relation::morphMap([
            'option' => Variants::option(),
            'option_value' => Variants::optionValue(),
            'variant' => Variants::variant(),
        ]);

        $this->publishes([
            __DIR__.'/config/variants.php' => config_path('variants.php'),
        ], 'variants-config');
    }
}
