<?php

declare(strict_types=1);

namespace InOtherShops\Storefront\Concerns;

use InOtherShops\Inventory\Contracts\HasStock;
use InOtherShops\Media\Contracts\HasMedia;
use InOtherShops\Pricing\Contracts\HasPrices;
use InOtherShops\Taxonomy\Contracts\HasCategories;
use InOtherShops\Taxonomy\Contracts\HasTags;
use InOtherShops\Translation\Contracts\Translatable;
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
        $locale = app()->getLocale();

        if (is_subclass_of($modelClass, Translatable::class)) {
            $relations['translations'] = fn ($q) => $q->where('locale', $locale);
        }

        if (is_subclass_of($modelClass, HasPrices::class)) {
            $relations[] = 'prices';
        }

        if (is_subclass_of($modelClass, HasCategories::class)) {
            $relations['categories.translations'] = fn ($q) => $q->where('locale', $locale);
            $relations[] = 'categories';
        }

        if (is_subclass_of($modelClass, HasTags::class)) {
            $relations['tags.translations'] = fn ($q) => $q->where('locale', $locale);
            $relations[] = 'tags';
        }

        if (is_subclass_of($modelClass, HasMedia::class)) {
            $relations[] = 'media';
        }

        if (is_subclass_of($modelClass, HasStock::class)) {
            $relations[] = 'stockItem';
        }

        return $relations;
    }
}
