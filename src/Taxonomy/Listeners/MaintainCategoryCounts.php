<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Listeners;

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InOtherShops\Taxonomy\Events\CategoryAttached;
use InOtherShops\Taxonomy\Events\CategoryDeleted;
use InOtherShops\Taxonomy\Events\CategoryDetached;
use InOtherShops\Taxonomy\Events\CategoryMoved;
use InOtherShops\Taxonomy\Exceptions\MorphAliasTooLongException;
use InOtherShops\Taxonomy\Support\CategoryAncestry;

/**
 * Keeps `category_morph_counts` consistent with the categorizables pivot
 * by propagating deltas along ancestor chains.
 *
 * Each row in `category_morph_counts` is the total number of items of a
 * given morph_alias attached to the category OR any of its descendants.
 * Reads on the nav side query this table directly — no recursion at read
 * time. Writes happen here, on the four lifecycle events above.
 *
 * Attach/detach shift a single +1/-1 up the ancestor chain. A move re-derives
 * the moved subtree's totals from the categorizables pivot (source of truth)
 * and shifts that off the old ancestor chain and onto the new one, so a move
 * over a drifted node corrects rather than spreads. A delete decrements the
 * snapshot the observer captured before the row (and its cascade) vanished.
 * The recovery command rebuilds from scratch if the invariant ever drifts.
 */
final class MaintainCategoryCounts
{
    /**
     * Must match the width of category_morph_counts.morph_alias. On MySQL with
     * strict mode off an over-length alias truncates silently — the truncated
     * value never matches the pivot's categorizable_type, so incremental
     * updates and recompute diverge permanently. We reject it up front instead.
     */
    private const int MORPH_ALIAS_MAX_LENGTH = 255;

    public function subscribe(Dispatcher $events): array
    {
        return [
            CategoryAttached::class => 'onAttached',
            CategoryDetached::class => 'onDetached',
            CategoryMoved::class => 'onMoved',
            CategoryDeleted::class => 'onDeleted',
        ];
    }

    public function onAttached(CategoryAttached $event): void
    {
        $alias = $this->guardAlias($event->model->getMorphClass());
        $parents = CategoryAncestry::parentMap();

        CategoryAncestry::walkUp((int) $event->category->getKey(), $parents, function (int $categoryId) use ($alias): void {
            $this->applyDelta($categoryId, $alias, 1);
        });
    }

    public function onDetached(CategoryDetached $event): void
    {
        $alias = $this->guardAlias($event->model->getMorphClass());
        $parents = CategoryAncestry::parentMap();

        CategoryAncestry::walkUp((int) $event->category->getKey(), $parents, function (int $categoryId) use ($alias): void {
            $this->applyDelta($categoryId, $alias, -1);
        });
    }

    private function guardAlias(string $alias): string
    {
        if (strlen($alias) > self::MORPH_ALIAS_MAX_LENGTH) {
            throw MorphAliasTooLongException::for($alias, self::MORPH_ALIAS_MAX_LENGTH);
        }

        return $alias;
    }

    public function onMoved(CategoryMoved $event): void
    {
        $parents = CategoryAncestry::parentMap();

        // Re-derive the moved subtree's totals from the pivot (source of truth)
        // rather than reading them off category_morph_counts. If the moved
        // node's counts row had drifted, reading it would shift a wrong delta
        // onto two ancestor chains and spread the drift; deriving from the
        // pivot keeps the move self-correcting. See audit B-6.
        $counts = $this->subtreeCounts((int) $event->category->getKey(), $parents);

        if ($counts === []) {
            return;
        }

        if ($event->oldParentId !== null) {
            CategoryAncestry::walkUp($event->oldParentId, $parents, function (int $categoryId) use ($counts): void {
                foreach ($counts as $alias => $count) {
                    $this->applyDelta($categoryId, $alias, -$count);
                }
            });
        }

        if ($event->newParentId !== null) {
            CategoryAncestry::walkUp($event->newParentId, $parents, function (int $categoryId) use ($counts): void {
                foreach ($counts as $alias => $count) {
                    $this->applyDelta($categoryId, $alias, $count);
                }
            });
        }
    }

    public function onDeleted(CategoryDeleted $event): void
    {
        if ($event->counts === [] || $event->parentId === null) {
            return;
        }

        $parents = CategoryAncestry::parentMap();

        CategoryAncestry::walkUp($event->parentId, $parents, function (int $categoryId) use ($event): void {
            foreach ($event->counts as $alias => $count) {
                $this->applyDelta($categoryId, $alias, -$count);
            }
        });
    }

    /**
     * Total items per morph alias attached to the category or any descendant,
     * counted from the categorizables pivot — the same aggregation the recompute
     * command performs, scoped to one subtree.
     *
     * @param  array<int, int|null>  $parents
     * @return array<string, int>
     */
    private function subtreeCounts(int $categoryId, array $parents): array
    {
        $subtreeIds = CategoryAncestry::descendants($categoryId, $parents);

        return DB::table('categorizables')
            ->whereIn('category_id', $subtreeIds)
            ->selectRaw('categorizable_type AS morph_alias, COUNT(*) AS aggregate')
            ->groupBy('categorizable_type')
            ->pluck('aggregate', 'morph_alias')
            ->map(fn ($value) => (int) $value)
            ->all();
    }

    /**
     * Shift one counter row by `$delta`.
     *
     * Increments upsert: the row may legitimately not exist yet. Decrements
     * deliberately do NOT — you never need to create a row in order to take
     * something off it, and on MySQL the upsert form is not merely redundant
     * but fatal. `count` is UNSIGNED, and MySQL assigns the VALUES row into the
     * record buffer BEFORE testing the duplicate key, so under
     * STRICT_TRANS_TABLES a negative delta raises 1264 "Out of range value"
     * and the ON DUPLICATE KEY UPDATE branch never runs — every detach/move/
     * delete failed, whatever the stored count. SQLite has no unsigned
     * enforcement and so never reproduced it.
     *
     * The guarded UPDATE also refuses to underflow. Affecting no row means the
     * counter had already drifted below what we are removing (or went missing);
     * we log that rather than clamping silently, because a floored-at-zero
     * counter is exactly the signal ReconcileCategoryCountsCommand exists to
     * catch. The count stays as-is and recompute is the repair.
     */
    private function applyDelta(int $categoryId, string $alias, int $delta): void
    {
        if ($delta === 0) {
            return;
        }

        if ($delta < 0) {
            $this->decrement($categoryId, $alias, -$delta);

            return;
        }

        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                'INSERT INTO category_morph_counts (category_id, morph_alias, count) VALUES (?, ?, ?) '
                .'ON DUPLICATE KEY UPDATE count = count + VALUES(count)',
                [$categoryId, $alias, $delta],
            );

            return;
        }

        DB::statement(
            'INSERT INTO category_morph_counts (category_id, morph_alias, count) VALUES (?, ?, ?) '
            .'ON CONFLICT (category_id, morph_alias) DO UPDATE SET count = category_morph_counts.count + EXCLUDED.count',
            [$categoryId, $alias, $delta],
        );
    }

    /**
     * @param  int  $amount  Positive magnitude to subtract.
     */
    private function decrement(int $categoryId, string $alias, int $amount): void
    {
        $affected = DB::table('category_morph_counts')
            ->where('category_id', $categoryId)
            ->where('morph_alias', $alias)
            ->where('count', '>=', $amount)
            ->decrement('count', $amount);

        if ($affected === 0) {
            Log::warning('Category count drift: decrement skipped, counter is below the amount being removed.', [
                'category_id' => $categoryId,
                'morph_alias' => $alias,
                'amount' => $amount,
                'remedy' => 'php artisan taxonomy:recompute-category-counts',
            ]);
        }
    }
}
