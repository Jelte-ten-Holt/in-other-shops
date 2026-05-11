<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds `category_morph_counts` from scratch by aggregating the
 * categorizables pivot and walking each direct count up the ancestor
 * chain. Recovery tool: the table is normally kept correct by
 * MaintainCategoryCounts on every relevant event, so this should only be
 * needed if the invariant ever drifts (e.g. a consumer hard-deleted a
 * categorized model without detaching, leaving orphan pivot rows).
 */
final class RecomputeCategoryCountsCommand extends Command
{
    protected $signature = 'taxonomy:recompute-category-counts';

    protected $description = 'Rebuild the category subtree-counts table from the categorizables pivot.';

    public function handle(): int
    {
        DB::transaction(function (): void {
            DB::table('category_morph_counts')->delete();

            $directCounts = DB::table('categorizables')
                ->selectRaw('category_id, categorizable_type AS morph_alias, COUNT(*) AS count')
                ->groupBy('category_id', 'categorizable_type')
                ->get();

            if ($directCounts->isEmpty()) {
                return;
            }

            $parents = DB::table('categories')
                ->pluck('parent_id', 'id')
                ->all();

            $aggregated = [];

            foreach ($directCounts as $row) {
                $categoryId = (int) $row->category_id;
                $alias = (string) $row->morph_alias;
                $count = (int) $row->count;

                $current = $categoryId;
                $seen = [];

                while ($current !== null) {
                    if (isset($seen[$current])) {
                        break;
                    }

                    $seen[$current] = true;

                    $aggregated[$current][$alias] = ($aggregated[$current][$alias] ?? 0) + $count;

                    $parent = $parents[$current] ?? null;
                    $current = $parent === null ? null : (int) $parent;
                }
            }

            $rows = [];

            foreach ($aggregated as $categoryId => $byAlias) {
                foreach ($byAlias as $alias => $count) {
                    $rows[] = [
                        'category_id' => $categoryId,
                        'morph_alias' => $alias,
                        'count' => $count,
                    ];
                }
            }

            if ($rows !== []) {
                DB::table('category_morph_counts')->insert($rows);
            }
        });

        $this->info('Category subtree counts recomputed.');

        return self::SUCCESS;
    }
}
