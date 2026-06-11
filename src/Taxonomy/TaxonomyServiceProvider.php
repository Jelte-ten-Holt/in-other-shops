<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy;

use Illuminate\Support\Facades\Event;
use InOtherShops\Support\DomainServiceProvider;
use InOtherShops\Taxonomy\Commands\RecomputeCategoryCountsCommand;
use InOtherShops\Taxonomy\Listeners\MaintainCategoryCounts;
use InOtherShops\Taxonomy\Observers\CategoryObserver;

final class TaxonomyServiceProvider extends DomainServiceProvider
{
    protected function domainDir(): string
    {
        return __DIR__;
    }

    protected function morphAliases(): array
    {
        return [
            'category' => Taxonomy::category(),
            'tag' => Taxonomy::tag(),
        ];
    }

    protected function domainCommands(): array
    {
        return [RecomputeCategoryCountsCommand::class];
    }

    public function boot(): void
    {
        parent::boot();

        Taxonomy::category()::observe(CategoryObserver::class);

        // Count maintenance, not audit logging — deliberately an explicit
        // subscribe here rather than the logSubscriber() hook (Taxonomy has
        // no LogSubscriber yet; admin-activity logging is deferred).
        Event::subscribe(MaintainCategoryCounts::class);
    }
}
