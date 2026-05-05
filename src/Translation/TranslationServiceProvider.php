<?php

declare(strict_types=1);

namespace InOtherShops\Translation;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

final class TranslationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/translation.php', 'translation');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        Relation::morphMap([
            'translation' => Translation::translation(),
            'locale_group' => Translation::localeGroup(),
        ]);
    }
}
