<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Support;

use Illuminate\Support\Facades\DB;

/**
 * Single home for walking the category parent_id tree. Three callers used to
 * each reimplement the walk with their own cycle posture — the listener and the
 * recompute command guarded against cycles, the migration backfill did not
 * (audit B-5). Centralizing the walk means one cycle guard, used everywhere.
 *
 * The walk takes a preloaded `id => parent_id` map rather than querying per
 * step, so a single SELECT replaces the per-ancestor N+1 the listener used to
 * issue inside checkout transactions (audit B-7).
 */
final class CategoryAncestry
{
    /**
     * @return array<int, int|null> id => parent_id for every category
     */
    public static function parentMap(): array
    {
        return DB::table('categories')
            ->pluck('parent_id', 'id')
            ->map(fn ($parentId) => $parentId === null ? null : (int) $parentId)
            ->all();
    }

    /**
     * Apply $visit to $startId and each ancestor, stopping at the root or the
     * first repeated id (cycle guard).
     *
     * @param  array<int, int|null>  $parents
     * @param  callable(int): void  $visit
     */
    public static function walkUp(int $startId, array $parents, callable $visit): void
    {
        $current = $startId;
        $seen = [];

        while ($current !== null) {
            if (isset($seen[$current])) {
                return;
            }

            $seen[$current] = true;

            $visit($current);

            $current = $parents[$current] ?? null;
        }
    }

    /**
     * $rootId plus every transitive descendant, cycle-guarded. Used to total a
     * moved subtree from the source-of-truth pivot rather than from the
     * possibly-drifted counts table (audit B-6).
     *
     * @param  array<int, int|null>  $parents
     * @return list<int>
     */
    public static function descendants(int $rootId, array $parents): array
    {
        $childrenByParent = [];
        foreach ($parents as $id => $parentId) {
            if ($parentId !== null) {
                $childrenByParent[$parentId][] = $id;
            }
        }

        $collected = [];
        $stack = [$rootId];

        while ($stack !== []) {
            $current = array_pop($stack);

            if (isset($collected[$current])) {
                continue;
            }

            $collected[$current] = true;

            foreach ($childrenByParent[$current] ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return array_keys($collected);
    }
}
