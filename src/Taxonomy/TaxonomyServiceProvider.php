<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

final class TaxonomyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/taxonomy.php', 'taxonomy');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        Relation::morphMap([
            'category' => Taxonomy::category(),
            'tag' => Taxonomy::tag(),
        ]);
    }
}
