<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use InOtherShops\Taxonomy\Commands\RecomputeCategoryCountsCommand;
use InOtherShops\Taxonomy\Listeners\MaintainCategoryCounts;
use InOtherShops\Taxonomy\Observers\CategoryObserver;

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

        Taxonomy::category()::observe(CategoryObserver::class);

        Event::subscribe(MaintainCategoryCounts::class);

        $this->commands([
            RecomputeCategoryCountsCommand::class,
        ]);
    }
}
