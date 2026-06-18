<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Support;

use Illuminate\Support\Facades\DB;

/**
 * Derives the *expected* `category_morph_counts` from the `categorizables`
 * pivot — each direct `(category, morph_alias)` count rolled up the ancestor
 * chain. This is the single truth function shared by the two commands that care
 * about it: `RecomputeCategoryCountsCommand` writes it, and
 * `ReconcileCategoryCounts` compares the live table against it. Keeping it in
 * one place means the repair and the tripwire can never disagree about what
 * "correct" is.
 */
final class CategoryCountAggregator
{
    /**
     * @return array<int, array<string, int>>  categoryId => [morph_alias => count]
     */
    public static function expected(): array
    {
        $directCounts = DB::table('categorizables')
            ->selectRaw('category_id, categorizable_type AS morph_alias, COUNT(*) AS count')
            ->groupBy('category_id', 'categorizable_type')
            ->get();

        if ($directCounts->isEmpty()) {
            return [];
        }

        $parents = CategoryAncestry::parentMap();

        $aggregated = [];

        foreach ($directCounts as $row) {
            $alias = (string) $row->morph_alias;
            $count = (int) $row->count;

            CategoryAncestry::walkUp((int) $row->category_id, $parents, function (int $categoryId) use (&$aggregated, $alias, $count): void {
                $aggregated[$categoryId][$alias] = ($aggregated[$categoryId][$alias] ?? 0) + $count;
            });
        }

        return $aggregated;
    }
}
