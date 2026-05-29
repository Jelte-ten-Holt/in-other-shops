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
 *
 * Concurrency (audit B-4): the rebuild is DELETE-then-write, which collides
 * with concurrent maintenance under the exact conditions recovery runs in
 * (degraded state, ongoing load). Two guards:
 *   - an advisory lock serializes simultaneous recompute runs (e.g. two
 *     operators, or a manual run alongside a scripted one);
 *   - the final write is an upsert, so a row a concurrent attach/detach
 *     created between the DELETE and the write resolves deterministically
 *     instead of crashing the command on a duplicate primary key.
 * The advisory lock is a no-op on SQLite, which has a single writer.
 */
final class RecomputeCategoryCountsCommand extends Command
{
    protected $signature = 'taxonomy:recompute-category-counts';

    protected $description = 'Rebuild the category subtree-counts table from the categorizables pivot.';

    private const string LOCK_KEY = 'in_other_shops:taxonomy:recompute_category_counts';

    private const int LOCK_TIMEOUT_SECONDS = 10;

    public function handle(): int
    {
        if (! $this->acquireLock()) {
            $this->error('Another category-counts recompute is already running; aborting.');

            return self::FAILURE;
        }

        try {
            $this->rebuild();
        } finally {
            $this->releaseLock();
        }

        $this->info('Category subtree counts recomputed.');

        return self::SUCCESS;
    }

    private function rebuild(): void
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
                DB::table('category_morph_counts')->upsert(
                    $rows,
                    ['category_id', 'morph_alias'],
                    ['count'],
                );
            }
        });
    }

    private function acquireLock(): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            return (int) DB::selectOne(
                'SELECT GET_LOCK(?, ?) AS acquired',
                [self::LOCK_KEY, self::LOCK_TIMEOUT_SECONDS],
            )->acquired === 1;
        }

        if ($driver === 'pgsql') {
            return (bool) DB::selectOne(
                'SELECT pg_try_advisory_lock(hashtext(?)::bigint) AS acquired',
                [self::LOCK_KEY],
            )->acquired;
        }

        // SQLite (and anything else): single writer, no advisory-lock support.
        return true;
    }

    private function releaseLock(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('DO RELEASE_LOCK(?)', [self::LOCK_KEY]);

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('SELECT pg_advisory_unlock(hashtext(?)::bigint)', [self::LOCK_KEY]);
        }
    }
}
