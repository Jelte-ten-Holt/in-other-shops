<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Filament;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InOtherShops\Commerce\Order\Support\OrderStockDemand;
use InOtherShops\Inventory\Contracts\HasStock;
use InOtherShops\Inventory\Enums\ReservationStatus;
use InOtherShops\Inventory\Inventory;
use InOtherShops\Inventory\Models\StockHold;
use InOtherShops\Inventory\Support\StockLocks;
use InOtherShops\Support\ModelLabel;

/**
 * What the admin order page shows and checks about stock before saving
 * (stock-holds brief §4.3): each line's split ("1 from Markt 15 Nov, 1 from
 * stock"), the holds the hold picker offers, and the field errors that stop a
 * save — main stock short of a line (D5), or short while open holds hold the
 * product, which names them (D11). Reads only; the save re-checks under the
 * locks (ReserveOrderStock).
 *
 * Works on the form's raw `lines` state (item key => line), so it runs
 * between renders, before anything is saved. The hold's quantities come from
 * the snapshot the page took when the hold was picked, never a fresh read: the
 * split shown and the split the save checks against must be the same numbers.
 */
final class OrderStockPreview
{
    public const string FROM_STOCK = 'stock';

    public const string FROM_HOLD = 'hold';

    public const string NO_STOCK = 'none';

    /**
     * Open holds holding at least one of the lines' products (§8 Q4), id => label.
     *
     * @param  array<array-key, mixed>  $lines
     * @return array<int, string>
     */
    public static function qualifyingHolds(array $lines): array
    {
        $stockItemIds = self::stockItemIdsOf(OrderStockDemand::fromLines(self::formLines($lines)));

        if ($stockItemIds === []) {
            return [];
        }

        return StockHold::query()
            ->whereNull('released_at')
            ->whereHas('pendingReservations', fn ($query) => $query->whereIn('stock_item_id', $stockItemIds))
            ->orderBy('held_for')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (StockHold $hold): array => [(int) $hold->getKey() => self::holdLabel($hold)])
            ->all();
    }

    public static function holdLabel(StockHold $hold): string
    {
        return $hold->held_for === null ? $hold->name : "{$hold->name} ({$hold->held_for->isoFormat('D MMM')})";
    }

    /**
     * Each line's split and each line's error, keyed by the form's item key.
     * A line that takes no stock has neither.
     *
     * @param  array<array-key, mixed>  $lines
     * @param  array<string, int>  $held  the hold snapshot, by stock group
     * @return array{splits: array<string, string>, errors: array<string, string>}
     */
    public static function analyse(array $lines, string $mode, array $held = [], ?string $holdName = null): array
    {
        if ($mode === self::NO_STOCK) {
            return ['splits' => [], 'errors' => []];
        }

        $keys = array_keys($lines);
        $entries = OrderStockDemand::split(
            OrderStockDemand::fromLines(self::formLines($lines)),
            $mode === self::FROM_HOLD ? $held : [],
        );

        $taken = [];
        $parts = [];
        $errors = [];

        foreach ($entries as $entry) {
            $key = (string) $keys[$entry['line']];
            $available = max(0, $entry['stockable']->stockLevel() - ($taken[$entry['group']] ?? 0));
            $taken[$entry['group']] = ($taken[$entry['group']] ?? 0) + $entry['fromStock'];
            $short = $entry['fromStock'] > $available;

            $parts[$key][] = self::describe($entry, $available, $short, $holdName, self::isComponent($lines[$keys[$entry['line']]], $entry['stockable']));

            if ($short) {
                $error = self::errorFor($entry, $available, $mode, $holdName);

                if ($error !== null) {
                    $errors[$key] ??= $error;
                }
            }
        }

        return [
            'splits' => array_map(fn (array $lineParts): string => implode('; ', $lineParts), $parts),
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $lines
     * @return list<array{orderable: ?Model, quantity: int, line: int}>
     */
    public static function formLines(array $lines): array
    {
        $formLines = [];

        foreach (array_values($lines) as $position => $line) {
            $formLines[] = [
                'orderable' => is_array($line) ? self::orderableOf($line) : null,
                'quantity' => is_array($line) ? (int) ($line['quantity'] ?? 0) : 0,
                'line' => $position,
            ];
        }

        return $formLines;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private static function orderableOf(array $line): ?Model
    {
        $type = $line['orderable_type'] ?? null;
        $id = $line['orderable_id'] ?? null;

        if (blank($type) || blank($id)) {
            return null;
        }

        $class = Relation::getMorphedModel((string) $type);

        return $class === null ? null : $class::query()->find($id);
    }

    /**
     * @param  array{stockable: Model&HasStock, quantity: int, line: int, group: string, fromHold: int, fromStock: int}  $entry
     */
    private static function describe(array $entry, int $available, bool $short, ?string $holdName, bool $isComponent): string
    {
        $pieces = [];

        if ($entry['fromHold'] > 0) {
            $pieces[] = __('shops-commerce::orders.stock.split.from_hold', ['count' => $entry['fromHold'], 'hold' => $holdName]);
        }

        if ($entry['fromStock'] > 0) {
            $backordered = $short && $entry['stockable']->allowsBackorder() ? $entry['fromStock'] - $available : 0;

            if ($entry['fromStock'] - $backordered > 0) {
                $pieces[] = __('shops-commerce::orders.stock.split.from_stock', ['count' => $entry['fromStock'] - $backordered]);
            }

            if ($backordered > 0) {
                $pieces[] = __('shops-commerce::orders.stock.split.backordered', ['count' => $backordered]);
            }
        }

        $split = implode(', ', $pieces);

        return $isComponent
            ? __('shops-commerce::orders.stock.split.component', ['item' => ModelLabel::for($entry['stockable']), 'split' => $split])
            : $split;
    }

    /**
     * @param  array{stockable: Model&HasStock, quantity: int, line: int, group: string, fromHold: int, fromStock: int}  $entry
     */
    private static function errorFor(array $entry, int $available, string $mode, ?string $holdName): ?string
    {
        if ($mode === self::FROM_STOCK) {
            // D11: keyed on main stock being short, not on a refusal — a
            // backorderable product is never refused, but the admin should
            // still learn the piece is in a hold.
            $holds = self::holdsHolding($entry['stockable']);

            if ($holds !== []) {
                return __('shops-commerce::orders.stock.errors.short_held', [
                    'available' => $available,
                    'held' => implode(', ', array_map(
                        fn (array $hold): string => __('shops-commerce::orders.stock.errors.held_in', $hold),
                        $holds,
                    )),
                ]);
            }
        }

        if ($entry['stockable']->allowsBackorder()) {
            return null;
        }

        return $entry['fromHold'] > 0
            ? __('shops-commerce::orders.stock.errors.short_after_hold', ['from_hold' => $entry['fromHold'], 'hold' => $holdName, 'available' => $available])
            : __('shops-commerce::orders.stock.errors.short', ['available' => $available]);
    }

    /**
     * Open holds holding the product, matched across its group.
     *
     * @return list<array{count: int, hold: string}>
     */
    private static function holdsHolding(Model&HasStock $stockable): array
    {
        $stockItemIds = StockLocks::stockItemIds($stockable);

        if ($stockItemIds === []) {
            return [];
        }

        $held = Inventory::stockReservation()::query()
            ->where('status', ReservationStatus::Pending)
            ->where('reference_type', (new StockHold)->getMorphClass())
            ->whereIn('stock_item_id', $stockItemIds)
            ->selectRaw('reference_id, sum(quantity) as held')
            ->groupBy('reference_id')
            ->pluck('held', 'reference_id');

        return StockHold::query()
            ->whereNull('released_at')
            ->whereKey($held->keys()->all())
            ->orderBy('id')
            ->get()
            ->map(fn (StockHold $hold): array => ['count' => (int) $held[$hold->getKey()], 'hold' => self::holdLabel($hold)])
            ->all();
    }

    /**
     * @param  list<array{stockable: Model&HasStock}>  $entries
     * @return list<int>
     */
    private static function stockItemIdsOf(array $entries): array
    {
        $ids = [];

        foreach ($entries as $entry) {
            array_push($ids, ...StockLocks::stockItemIds($entry['stockable']));
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  mixed  $line
     */
    private static function isComponent($line, Model $stockable): bool
    {
        $orderable = is_array($line) ? self::orderableOf($line) : null;

        return $orderable !== null && ! $orderable->is($stockable);
    }
}
