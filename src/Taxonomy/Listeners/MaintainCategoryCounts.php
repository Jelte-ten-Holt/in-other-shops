<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Listeners;

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use InOtherShops\Taxonomy\Events\CategoryAttached;
use InOtherShops\Taxonomy\Events\CategoryDeleted;
use InOtherShops\Taxonomy\Events\CategoryDetached;
use InOtherShops\Taxonomy\Events\CategoryMoved;
use InOtherShops\Taxonomy\Exceptions\MorphAliasTooLongException;

/**
 * Keeps `category_morph_counts` consistent with the categorizables pivot
 * by propagating deltas along ancestor chains.
 *
 * Each row in `category_morph_counts` is the total number of items of a
 * given morph_alias attached to the category OR any of its descendants.
 * Reads on the nav side query this table directly — no recursion at read
 * time. Writes happen here, on the four lifecycle events above.
 *
 * Tree mutations (move, delete) propagate by shifting the moved/deleted
 * category's existing row totals up the relevant ancestor chains, not by
 * rebuilding from the pivot. The recovery command rebuilds from scratch
 * if the invariant ever drifts.
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

        $this->walkAncestors((int) $event->category->getKey(), function (int $categoryId) use ($alias): void {
            $this->applyDelta($categoryId, $alias, 1);
        });
    }

    public function onDetached(CategoryDetached $event): void
    {
        $alias = $this->guardAlias($event->model->getMorphClass());

        $this->walkAncestors((int) $event->category->getKey(), function (int $categoryId) use ($alias): void {
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
        $counts = $this->loadCounts((int) $event->category->getKey());

        if ($counts === []) {
            return;
        }

        if ($event->oldParentId !== null) {
            $this->walkAncestors($event->oldParentId, function (int $categoryId) use ($counts): void {
                foreach ($counts as $alias => $count) {
                    $this->applyDelta($categoryId, $alias, -$count);
                }
            });
        }

        if ($event->newParentId !== null) {
            $this->walkAncestors($event->newParentId, function (int $categoryId) use ($counts): void {
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

        $this->walkAncestors($event->parentId, function (int $categoryId) use ($event): void {
            foreach ($event->counts as $alias => $count) {
                $this->applyDelta($categoryId, $alias, -$count);
            }
        });
    }

    /**
     * @param  callable(int): void  $apply
     */
    private function walkAncestors(int $startId, callable $apply): void
    {
        $current = $startId;
        $seen = [];

        while ($current !== null) {
            if (isset($seen[$current])) {
                return;
            }

            $seen[$current] = true;

            $apply($current);

            $parent = DB::table('categories')->where('id', $current)->value('parent_id');
            $current = $parent === null ? null : (int) $parent;
        }
    }

    /**
     * @return array<string, int>
     */
    private function loadCounts(int $categoryId): array
    {
        return DB::table('category_morph_counts')
            ->where('category_id', $categoryId)
            ->pluck('count', 'morph_alias')
            ->map(fn ($value) => (int) $value)
            ->all();
    }

    private function applyDelta(int $categoryId, string $alias, int $delta): void
    {
        if ($delta === 0) {
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
}
