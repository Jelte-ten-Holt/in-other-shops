<?php

declare(strict_types=1);

namespace InOtherShops\Storefront\Actions;

use InOtherShops\Storefront\Concerns\ResolvesEagerLoading;
use InOtherShops\Storefront\Contracts\Browsable;
use InOtherShops\Taxonomy\Contracts\HasCategories;
use InOtherShops\Taxonomy\Contracts\HasTags;
use InOtherShops\Taxonomy\Taxonomy;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

final class ListBrowsables
{
    use ResolvesEagerLoading;

    /**
     * @param  class-string<Browsable>  $modelClass
     */
    public function __invoke(string $modelClass, Request $request): LengthAwarePaginator
    {
        $query = $modelClass::browseQuery();

        $this->eagerLoadForContracts($query, $modelClass);
        $this->filterByCategory($query, $modelClass, $request);
        $this->filterByTag($query, $modelClass, $request);
        $this->filterBySearch($query, $request);
        $this->applySortOrder($query, $request);

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

    private function filterBySearch(Builder $query, Request $request): void
    {
        $search = $request->input('search');

        if (! is_string($search) || $search === '') {
            return;
        }

        $query->where(function (Builder $q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%");
        });
    }

    private function applySortOrder(Builder $query, Request $request): void
    {
        $sort = $request->input('sort');
        $allowed = ['name', 'created_at', 'published_at'];

        if (is_string($sort)) {
            $direction = 'asc';

            if (str_starts_with($sort, '-')) {
                $direction = 'desc';
                $sort = substr($sort, 1);
            }

            if (in_array($sort, $allowed, true)) {
                $query->orderBy($sort, $direction);

                return;
            }
        }

        $query->latest('published_at');
    }

    private function paginate(Builder $query, Request $request): LengthAwarePaginator
    {
        $perPage = min((int) $request->input('per_page', config('storefront.defaults.per_page', 24)), 100);

        return $query->paginate($perPage)->withQueryString();
    }
}
