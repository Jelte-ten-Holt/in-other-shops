<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Actions;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use InOtherShops\Taxonomy\Models\Category;
use InOtherShops\Taxonomy\Taxonomy;

/**
 * Returns the active category tree filtered to nodes that have any item of
 * the given morph aliases attached in their subtree. Each returned node
 * carries a `relevant_count` attribute summed across the queried aliases,
 * suitable for rendering nav badges.
 *
 * Reads category_morph_counts (maintained by MaintainCategoryCounts), so
 * the query stays O(rows-with-counts) — no recursive descent at read time.
 * A node with descendants tagged but nothing of its own appears because
 * the counts table is pre-aggregated up the ancestor chain.
 */
final class ListCategoryTree
{
    /**
     * @param  array<int, string>  $morphAliases
     * @return EloquentCollection<int, Category>
     */
    public function __invoke(array $morphAliases): EloquentCollection
    {
        if ($morphAliases === []) {
            return new EloquentCollection;
        }

        $countsByCategoryId = $this->loadRelevantCounts($morphAliases);

        if ($countsByCategoryId === []) {
            return new EloquentCollection;
        }

        $categories = $this->loadCategoriesIn(array_keys($countsByCategoryId));

        $this->annotateCounts($categories, $countsByCategoryId);

        return $this->nestAsTree($categories);
    }

    /**
     * @param  array<int, string>  $aliases
     * @return array<int, int>
     */
    private function loadRelevantCounts(array $aliases): array
    {
        $rows = DB::table('category_morph_counts')
            ->select('category_id', DB::raw('SUM(count) AS total'))
            ->whereIn('morph_alias', $aliases)
            ->where('count', '>', 0)
            ->groupBy('category_id')
            ->get();

        $byCategory = [];

        foreach ($rows as $row) {
            $byCategory[(int) $row->category_id] = (int) $row->total;
        }

        return $byCategory;
    }

    /**
     * @param  array<int, int>  $ids
     * @return EloquentCollection<int, Category>
     */
    private function loadCategoriesIn(array $ids): EloquentCollection
    {
        /** @var class-string<Category> $model */
        $model = Taxonomy::category();

        return $model::query()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->orderBy('position')
            ->get();
    }

    /**
     * @param  EloquentCollection<int, Category>  $categories
     * @param  array<int, int>  $countsByCategoryId
     */
    private function annotateCounts(EloquentCollection $categories, array $countsByCategoryId): void
    {
        foreach ($categories as $category) {
            $category->setAttribute('relevant_count', $countsByCategoryId[$category->id] ?? 0);
        }
    }

    /**
     * @param  EloquentCollection<int, Category>  $categories
     * @return EloquentCollection<int, Category>
     */
    private function nestAsTree(EloquentCollection $categories): EloquentCollection
    {
        $byParent = [];
        $idsInSet = [];

        foreach ($categories as $category) {
            $idsInSet[$category->id] = true;
            $byParent[$category->parent_id][] = $category;
        }

        foreach ($categories as $category) {
            $children = $byParent[$category->id] ?? [];
            $category->setRelation('children', new EloquentCollection($children));
        }

        $roots = [];

        foreach ($categories as $category) {
            $parentId = $category->parent_id;

            // A category is a root of the visible tree when it has no parent,
            // OR when its parent is filtered out (inactive / no subtree counts).
            // Without the second case, whole subtrees would silently disappear
            // when an ancestor is deactivated.
            if ($parentId === null || ! isset($idsInSet[$parentId])) {
                $roots[] = $category;
            }
        }

        return new EloquentCollection($roots);
    }
}
