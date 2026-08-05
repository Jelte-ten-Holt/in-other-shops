<?php

declare(strict_types=1);

namespace InOtherShops\Storefront\Actions;

use InOtherShops\Storefront\Concerns\ResolvesEagerLoading;
use InOtherShops\Storefront\Contracts\HasStorefrontPresence;
use InOtherShops\Taxonomy\Contracts\HasCategories;
use InOtherShops\Taxonomy\Contracts\HasTags;
use InOtherShops\Taxonomy\Taxonomy;
use InOtherShops\Translation\Contracts\HasTranslations;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

final class ListBrowsables
{
    use ResolvesEagerLoading;

    /** @var array<string, bool> Memoized `{class}:{column}` schema lookups. */
    private static array $columnCache = [];

    /**
     * @param  class-string<HasStorefrontPresence>  $modelClass
     */
    public function __invoke(string $modelClass, Request $request): LengthAwarePaginator
    {
        $query = $modelClass::browseQuery();

        $this->eagerLoadForContracts($query, $modelClass);
        $this->filterByCategory($query, $modelClass, $request);
        $this->filterByTag($query, $modelClass, $request);
        $this->filterBySearch($query, $modelClass, $request);
        $this->applySortOrder($query, $modelClass, $request);

        return $this->paginate($query, $request);
    }

    private function filterByCategory(Builder $query, string $modelClass, Request $request): void
    {
        if (! is_subclass_of($modelClass, HasCategories::class) || ! $request->has('category')) {
            return;
        }

        $category = Taxonomy::category()->query()->where('slug', $request->input('category'))->first();

        if ($category !== null) {
            $query->whereHas('categories', fn (Builder $q) => $q->where('categories.id', $category->id));
        }
    }

    private function filterByTag(Builder $query, string $modelClass, Request $request): void
    {
        if (! is_subclass_of($modelClass, HasTags::class) || ! $request->has('tag')) {
            return;
        }

        $tag = Taxonomy::tag()->query()->where('slug', $request->input('tag'))->first();

        if ($tag !== null) {
            $query->whereHas('tags', fn (Builder $q) => $q->where('tags.id', $tag->id));
        }
    }

    /**
     * Search name and description, wherever this consumer keeps them.
     *
     * `name` and `description` are contract METHODS, never promised columns.
     * One consumer stores them as columns; another stores them as rows in the
     * `translations` table. A flat `where('name', 'like', …)` is therefore only
     * correct by accident — against a translation-backed catalog it fails with
     * "Unknown column 'name'", turning the whole listing into a 500 the moment
     * a shopper types in the search box.
     *
     * Each field is routed to whichever storage this model actually uses, and
     * a field the model has in neither form is skipped rather than guessed at.
     */
    private function filterBySearch(Builder $query, string $modelClass, Request $request): void
    {
        $search = $request->input('search');

        if (! is_string($search) || $search === '') {
            return;
        }

        $translatable = $this->translatableFields($modelClass);

        $query->where(function (Builder $q) use ($modelClass, $search, $translatable): void {
            foreach (['name', 'description'] as $field) {
                if (in_array($field, $translatable, true)) {
                    $q->orWhere(fn (Builder $inner) => $inner->whereTranslation($field, 'like', "%{$search}%"));

                    continue;
                }

                if ($this->hasColumn($modelClass, $field)) {
                    $q->orWhere($field, 'like', "%{$search}%");
                }
            }
        });
    }

    private function applySortOrder(Builder $query, string $modelClass, Request $request): void
    {
        $sort = $request->input('sort');
        $allowed = ['name', 'created_at', 'published_at'];

        if (is_string($sort)) {
            $direction = 'asc';

            if (str_starts_with($sort, '-')) {
                $direction = 'desc';
                $sort = substr($sort, 1);
            }

            if (in_array($sort, $allowed, true) && $this->applySort($query, $modelClass, $sort, $direction)) {
                return;
            }
        }

        $query->latest('published_at');
    }

    /**
     * Returns false when the requested sort is not expressible for this model,
     * so the caller falls through to the default ordering rather than emitting
     * SQL against a column that isn't there.
     */
    private function applySort(Builder $query, string $modelClass, string $sort, string $direction): bool
    {
        if (in_array($sort, $this->translatableFields($modelClass), true)) {
            $query->orderByTranslation($sort, $direction);

            return true;
        }

        if (! $this->hasColumn($modelClass, $sort)) {
            return false;
        }

        $query->orderBy($sort, $direction);

        return true;
    }

    /**
     * @param  class-string  $modelClass
     * @return array<string>
     */
    private function translatableFields(string $modelClass): array
    {
        if (! is_a($modelClass, HasTranslations::class, true)) {
            return [];
        }

        return (new $modelClass)->translatableFields();
    }

    /**
     * Schema introspection is a per-connection round trip, so memoize it. The
     * set of columns cannot change within a request.
     *
     * @param  class-string  $modelClass
     */
    private function hasColumn(string $modelClass, string $column): bool
    {
        $model = new $modelClass;
        $key = $modelClass.':'.$column;

        return self::$columnCache[$key] ??= $model->getConnection()
            ->getSchemaBuilder()
            ->hasColumn($model->getTable(), $column);
    }

    private function paginate(Builder $query, Request $request): LengthAwarePaginator
    {
        // Floor at 1 as well as capping at 100: a per_page of 0 or negative
        // would otherwise reach paginate() (0 = "all rows", negative = undefined)
        // — invalid input must degrade to a single-item page, not dump the table.
        $requested = (int) $request->input('per_page', config('storefront.defaults.per_page', 24));
        $perPage = min(max($requested, 1), 100);

        return $query->paginate($perPage)->withQueryString();
    }
}
