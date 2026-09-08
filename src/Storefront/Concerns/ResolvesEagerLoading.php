<?php

declare(strict_types=1);

namespace InOtherShops\Storefront\Concerns;

use InOtherShops\Inventory\Contracts\HasStock;
use InOtherShops\Pricing\Contracts\HasPrices;
use InOtherShops\Taxonomy\Contracts\HasCategories;
use InOtherShops\Taxonomy\Contracts\HasTags;
use InOtherShops\Translation\Contracts\HasTranslations;
use Illuminate\Database\Eloquent\Builder;

trait ResolvesEagerLoading
{
    /**
     * @param  class-string  $modelClass
     */
    private function eagerLoadForContracts(Builder $query, string $modelClass): void
    {
        $relations = $this->resolveRelations($modelClass);

        if ($relations !== []) {
            $query->with($relations);
        }
    }

    /**
     * @param  class-string  $modelClass
     * @return array<string, \Closure|string>
     */
    private function resolveRelations(string $modelClass): array
    {
        $relations = [];

        // Both the current locale AND the fallback: `findFallbackTranslation`
        // searches this same eager-loaded collection, so constraining it to the
        // current locale alone hid the fallback row and listed an item
        // translated only in the fallback locale with `name = null` (X4).
        $locales = array_values(array_unique(array_filter([
            app()->getLocale(),
            config('translation.fallback'),
        ])));

        if (is_subclass_of($modelClass, HasTranslations::class)) {
            $relations['translations'] = fn ($q) => $q->whereIn('locale', $locales);
        }

        if (is_subclass_of($modelClass, HasPrices::class)) {
            $relations[] = 'prices';
        }

        if (is_subclass_of($modelClass, HasCategories::class)) {
            // The nested `categories.translations` already eager-loads the
            // `categories` relation itself — a bare 'categories' key is redundant.
            $relations['categories.translations'] = fn ($q) => $q->whereIn('locale', $locales);
        }

        if (is_subclass_of($modelClass, HasTags::class)) {
            $relations['tags.translations'] = fn ($q) => $q->whereIn('locale', $locales);
        }

        if (is_subclass_of($modelClass, HasStock::class)) {
            $relations[] = 'stockItem';
        }

        return $relations;
    }
}
