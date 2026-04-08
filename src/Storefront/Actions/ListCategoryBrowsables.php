<?php

declare(strict_types=1);

namespace InOtherShops\Storefront\Actions;

use InOtherShops\Storefront\Concerns\ResolvesEagerLoading;
use InOtherShops\Storefront\Contracts\Browsable;
use InOtherShops\Taxonomy\Contracts\HasCategories;
use InOtherShops\Taxonomy\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class ListCategoryBrowsables
{
    use ResolvesEagerLoading;

    public function __invoke(Category $category, int $perPage = 24, int $page = 1): LengthAwarePaginator
    {
        $models = $this->categorizableBrowsables();
        $items = $this->collectItemsFromAllModels($models, $category);
        $sorted = $this->sortByNewest($items);

        return $this->paginate($sorted, $perPage, $page);
    }

    /**
     * @return array<string, class-string<Browsable&HasCategories>>
     */
    private function categorizableBrowsables(): array
    {
        $eligible = [];

        /** @var array<string, class-string> $models */
        $models = config('storefront.models', []);

        foreach ($models as $type => $modelClass) {
            if (is_subclass_of($modelClass, Browsable::class) && is_subclass_of($modelClass, HasCategories::class)) {
                $eligible[$type] = $modelClass;
            }
        }

        return $eligible;
    }

    /**
     * @param  array<string, class-string<Browsable&HasCategories>>  $models
     */
    private function collectItemsFromAllModels(array $models, Category $category): Collection
    {
        $all = new Collection;

        foreach ($models as $type => $modelClass) {
            $items = $this->queryModelForCategory($modelClass, $category);
            $items->each(fn ($item) => $item->setAttribute('browsable_type', $type));
            $all = $all->merge($items);
        }

        return $all;
    }

    /**
     * @param  class-string<Browsable&HasCategories>  $modelClass
     */
    private function queryModelForCategory(string $modelClass, Category $category): Collection
    {
        $query = $modelClass::browseQuery()
            ->whereHas('categories', fn (Builder $q) => $q->where('categories.id', $category->id));

        $this->eagerLoadForContracts($query, $modelClass);

        return $query->get();
    }

    private function sortByNewest(Collection $items): Collection
    {
        return $items->sortByDesc(fn ($item) => $item->published_at ?? $item->created_at)->values();
    }

    private function paginate(Collection $items, int $perPage, int $page): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            items: $items->forPage($page, $perPage)->values(),
            total: $items->count(),
            perPage: $perPage,
            currentPage: $page,
        );
    }
}
