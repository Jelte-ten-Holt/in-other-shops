<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Actions;

use InOtherShops\Taxonomy\Models\Category;
use InOtherShops\Taxonomy\Taxonomy;

/**
 * Returns every category id in depth-first parent→child order: each parent is
 * immediately followed by its descendants, with siblings ordered by
 * (position, id). Drives the admin index so a flat Filament table reads as a
 * tree (parent first, its children beneath it).
 *
 * Orphans — rows whose parent_id points at a missing category — surface as
 * roots so no subtree silently disappears (mirrors ListCategoryTree). A
 * visited guard makes a corrupt parent cycle terminate rather than recurse
 * forever.
 */
final class ListCategoriesInTreeOrder
{
    /** @return list<int> */
    public function __invoke(): array
    {
        [$ids, $childrenByParent] = $this->index();

        $visited = [];
        $ordered = [];

        foreach ($this->rootIds($ids, $childrenByParent) as $rootId) {
            $this->appendSubtree($rootId, $childrenByParent, $visited, $ordered);
        }

        return $ordered;
    }

    /**
     * Loads every category as (id, parent_id), already sibling-sorted, and
     * groups children under their parent. Null parents are keyed under 0 — safe
     * because auto-increment ids start at 1.
     *
     * @return array{0: array<int, true>, 1: array<int, list<int>>}
     */
    private function index(): array
    {
        /** @var class-string<Category> $model */
        $model = Taxonomy::category();

        $rows = $model::query()
            ->select(['id', 'parent_id'])
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $ids = [];
        $childrenByParent = [];

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $ids[$id] = true;
            $childrenByParent[$row->parent_id !== null ? (int) $row->parent_id : 0][] = $id;
        }

        return [$ids, $childrenByParent];
    }

    /**
     * Roots are top-level categories (null parent) plus orphans whose parent is
     * absent from the set — appended after the genuine roots so they still show.
     *
     * @param  array<int, true>  $ids
     * @param  array<int, list<int>>  $childrenByParent
     * @return list<int>
     */
    private function rootIds(array $ids, array $childrenByParent): array
    {
        $roots = $childrenByParent[0] ?? [];

        foreach ($childrenByParent as $parentId => $children) {
            if ($parentId === 0 || isset($ids[$parentId])) {
                continue;
            }

            foreach ($children as $orphanId) {
                $roots[] = $orphanId;
            }
        }

        return $roots;
    }

    /**
     * @param  array<int, list<int>>  $childrenByParent
     * @param  array<int, true>  $visited
     * @param  list<int>  $ordered
     */
    private function appendSubtree(int $id, array $childrenByParent, array &$visited, array &$ordered): void
    {
        if (isset($visited[$id])) {
            return;
        }

        $visited[$id] = true;
        $ordered[] = $id;

        foreach ($childrenByParent[$id] ?? [] as $childId) {
            $this->appendSubtree($childId, $childrenByParent, $visited, $ordered);
        }
    }
}
