<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use InOtherShops\Inventory\Contracts\HasStock;
use InOtherShops\Inventory\DTOs\Stock;
use InOtherShops\Inventory\Models\StockItem;
use InOtherShops\Translation\Contracts\HasLocaleGroup;

/**
 * The one lock order for stock rows (stock-holds brief §4.2).
 *
 * Every actor that writes reservations takes its locks in the same order: the
 * hold row when a hold is involved, then **every stock item it will touch, all
 * of them up front, sorted**, then reservation rows, then inserts. A `FOR
 * UPDATE` scan of reservation rows takes next-key (gap) locks on MySQL, so an
 * actor that scanned rows before holding the stock item could wait on a
 * checkout that already holds the stock item and is waiting on that gap to
 * insert its own reservation — a deadlock on the last-piece race. Holding every
 * stock item first closes it: anyone who can insert into, or release from, a
 * stock item's range must hold that stock item.
 *
 * A stockable in a shared-inventory locale group touches every sibling's stock
 * item (AdjustStock writes the whole group), so "the stock items it touches"
 * means the group's, and the group is also what a hold is matched across.
 */
final class StockItemLocks
{
    /**
     * Lock the stock items of every given stockable's group, in the global
     * order. Re-locking a row this transaction already holds is a no-op, so
     * nested actors may call it again for a subset.
     *
     * @param  iterable<Model&HasStock>  $stockables
     */
    public static function lock(iterable $stockables): void
    {
        $targets = [];

        foreach ($stockables as $stockable) {
            foreach (self::targets($stockable) as $target) {
                $targets[self::key($target)] = $target;
            }
        }

        foreach (self::sorted(array_values($targets)) as $target) {
            self::findOrCreateLocked($target);
        }
    }

    /**
     * The stockables a write to this one hits: its shared-inventory locale
     * group (self included) when it has one, else itself.
     *
     * @return list<Model&HasStock>
     */
    public static function targets(Model&HasStock $stockable): array
    {
        if (! ($stockable instanceof HasLocaleGroup)) {
            return [$stockable];
        }

        $group = $stockable->localeGroup;

        if ($group === null || ! $group->shares_inventory) {
            return [$stockable];
        }

        return [$stockable, ...$stockable->siblings()->get()->all()];
    }

    /**
     * Sort by a stable composite key (morph class + primary key), so the lock
     * order is independent of which member of a group, or which line of an
     * order, an actor started from (G7).
     *
     * @param  list<Model&HasStock>  $stockables
     * @return list<Model&HasStock>
     */
    public static function sorted(array $stockables): array
    {
        usort(
            $stockables,
            static fn (Model $a, Model $b): int => [$a->getMorphClass(), $a->getKey()] <=> [$b->getMorphClass(), $b->getKey()],
        );

        return $stockables;
    }

    /**
     * A key naming the stock a stockable sells from: the first member of its
     * group in lock order. Two siblings of a shared-inventory group have the
     * same key; a hold on one is a hold on the other.
     */
    public static function groupKey(Model&HasStock $stockable): string
    {
        return self::key(self::sorted(self::targets($stockable))[0]);
    }

    /**
     * The ids of the stock items a stockable's group sells from, for matching
     * reservations across the group. Reads; takes no lock.
     *
     * @return list<int>
     */
    public static function stockItemIds(Model&HasStock $stockable): array
    {
        $ids = [];

        foreach (self::targets($stockable) as $target) {
            $id = $target->stockItem()->value('id');

            if ($id !== null) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * Acquire a row-locked StockItem for the given stockable, creating it if
     * absent. The lock serializes concurrent writers to the same stockable so
     * that read-then-write (availability check → reserve) cannot oversell.
     */
    public static function findOrCreateLocked(Model&HasStock $stockable): StockItem
    {
        $stockItem = $stockable->stockItem()->lockForUpdate()->first();

        if ($stockItem !== null) {
            return $stockItem;
        }

        try {
            return $stockable->stockItem()->create(['stock_level' => new Stock(0)]);
        } catch (UniqueConstraintViolationException) {
            /** @var StockItem */
            return $stockable->stockItem()->lockForUpdate()->first();
        }
    }

    private static function key(Model $stockable): string
    {
        return $stockable->getMorphClass().':'.$stockable->getKey();
    }
}
