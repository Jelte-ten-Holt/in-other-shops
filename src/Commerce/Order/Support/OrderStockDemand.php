<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Support;

use Illuminate\Database\Eloquent\Model;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Commerce\Order\Models\OrderLine;
use InOtherShops\Inventory\Contracts\HasStock;
use InOtherShops\Inventory\Contracts\HasStockComponents;
use InOtherShops\Inventory\Support\StockLocks;

/**
 * What an admin order's lines take from stock, and how that splits between a
 * hold and main stock (stock-holds brief §4.2, §4.3, D5). One arithmetic for
 * both sides: the order page shows each line's split from it, and
 * ReserveOrderStock re-runs it under the locks and refuses a save whose split
 * came out different from the one shown.
 *
 * A line with no item takes nothing; a stockable that does not track stock
 * takes nothing; a bundle line takes each of its components
 * ({@see HasStockComponents}). Entries are sorted in the global lock order
 * (morph class, key), stable by line, so the hold is consumed in the same
 * order whichever side runs it.
 *
 * @phpstan-type Entry array{stockable: Model&HasStock, quantity: int, line: int, group: string}
 * @phpstan-type SplitEntry array{stockable: Model&HasStock, quantity: int, line: int, group: string, fromHold: int, fromStock: int}
 */
final class OrderStockDemand
{
    /**
     * @param  iterable<array{orderable: ?Model, quantity: int|string|null, line: int}>  $lines
     * @return list<Entry>
     */
    public static function fromLines(iterable $lines): array
    {
        $entries = [];

        foreach ($lines as $line) {
            $quantity = (int) ($line['quantity'] ?? 0);

            if ($quantity <= 0) {
                continue;
            }

            foreach (self::stockablesOf($line['orderable']) as [$stockable, $perUnit]) {
                $entries[] = [
                    'stockable' => $stockable,
                    'quantity' => $perUnit * $quantity,
                    'line' => (int) $line['line'],
                    'group' => StockLocks::groupKey($stockable),
                ];
            }
        }

        return self::sorted($entries);
    }

    /**
     * The saved order's lines, in the order they were written.
     *
     * @return list<Entry>
     */
    public static function forOrder(Order $order): array
    {
        $lines = $order->lines()->with('orderable')->orderBy('id')->get()->values();

        return self::fromLines($lines->map(fn (OrderLine $line, int $index): array => [
            'orderable' => $line->orderable,
            'quantity' => $line->quantity,
            'line' => $index,
        ]));
    }

    /**
     * Consume what the hold holds, entry by entry; the rest comes from main
     * stock. Pure.
     *
     * @param  list<Entry>  $entries
     * @param  array<string, int>  $heldByGroup
     * @return list<SplitEntry>
     */
    public static function split(array $entries, array $heldByGroup): array
    {
        $remaining = $heldByGroup;

        return array_map(function (array $entry) use (&$remaining): array {
            $fromHold = min($entry['quantity'], max(0, $remaining[$entry['group']] ?? 0));
            $remaining[$entry['group']] = ($remaining[$entry['group']] ?? 0) - $fromHold;

            return [...$entry, 'fromHold' => $fromHold, 'fromStock' => $entry['quantity'] - $fromHold];
        }, $entries);
    }

    /**
     * @param  list<SplitEntry>  $split
     * @return array<string, int>  units taken from the hold, by stock group; groups taking none are left out
     */
    public static function fromHoldByGroup(array $split): array
    {
        $byGroup = [];

        foreach ($split as $entry) {
            if ($entry['fromHold'] > 0) {
                $byGroup[$entry['group']] = ($byGroup[$entry['group']] ?? 0) + $entry['fromHold'];
            }
        }

        ksort($byGroup);

        return $byGroup;
    }

    /**
     * @return list<array{Model&HasStock, int}>  stockable and quantity per unit of the orderable
     */
    private static function stockablesOf(?Model $orderable): array
    {
        if ($orderable instanceof HasStockComponents) {
            $components = [];

            foreach ($orderable->stockComponents() as $component) {
                if ($component->stockable->tracksStock() && $component->quantity > 0) {
                    $components[] = [$component->stockable, $component->quantity];
                }
            }

            return $components;
        }

        if ($orderable instanceof HasStock && $orderable->tracksStock()) {
            return [[$orderable, 1]];
        }

        return [];
    }

    /**
     * @param  list<Entry>  $entries
     * @return list<Entry>
     */
    private static function sorted(array $entries): array
    {
        $indexed = array_map(null, array_keys($entries), $entries);

        usort($indexed, static fn (array $a, array $b): int => [$a[1]['stockable']->getMorphClass(), $a[1]['stockable']->getKey(), $a[0]]
            <=> [$b[1]['stockable']->getMorphClass(), $b[1]['stockable']->getKey(), $b[0]]);

        return array_map(static fn (array $pair): array => $pair[1], $indexed);
    }
}
