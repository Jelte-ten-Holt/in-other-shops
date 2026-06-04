<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Actions;

use InOtherShops\Inventory\Contracts\HasStock;
use InOtherShops\Inventory\DTOs\Stock;
use InOtherShops\Inventory\Enums\StockMovementReason;
use InOtherShops\Inventory\Events\StockAdjusted;
use InOtherShops\Inventory\Models\StockItem;
use InOtherShops\Inventory\Models\StockMovement;
use InOtherShops\Translation\Contracts\HasLocaleGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class AdjustStock
{
    public function __invoke(
        Model&HasStock $stockable,
        int $quantity,
        StockMovementReason $reason,
        ?string $description = null,
        ?Model $reference = null,
        ?string $source = null,
    ): StockMovement {
        $this->validateSource($source);

        $targets = $this->orderForLocking($this->resolveTargets($stockable));

        $results = DB::transaction(function () use ($targets, $stockable, $quantity, $reason, $description, $reference, $source): array {
            $out = [];
            foreach ($targets as $target) {
                $stockItem = $this->findOrCreateStockItem($target);
                $movement = $this->createMovement($stockItem, $quantity, $reason, $description, $reference, $source);
                $this->updateStockLevel($stockItem, $quantity);

                $out[] = [$movement, $stockItem->refresh(), $stockable->is($target)];
            }

            return $out;
        });

        $primaryMovement = null;
        foreach ($results as [$movement, $stockItem, $isPrimary]) {
            StockAdjusted::dispatch($movement, $stockItem);

            if ($isPrimary) {
                $primaryMovement = $movement;
            }
        }

        /** @var StockMovement */
        return $primaryMovement;
    }

    /**
     * Returns the set of stockables that this adjustment should hit. When the
     * stockable is in a LocaleGroup with shares_inventory=true, all siblings
     * (plus self) participate atomically. Otherwise just self.
     *
     * @return list<Model&HasStock>
     */
    private function resolveTargets(Model&HasStock $stockable): array
    {
        if (! ($stockable instanceof HasLocaleGroup)) {
            return [$stockable];
        }

        $group = $stockable->localeGroup;

        if ($group === null || ! $group->shares_inventory) {
            return [$stockable];
        }

        $siblings = $stockable->siblings()->get()->all();

        return [$stockable, ...$siblings];
    }

    /**
     * Acquire the group's per-row locks in a deterministic global order (G7).
     * The target SET is identical for every member of a shared-inventory group,
     * but {@see resolveTargets} lists the *entry* member first — so two concurrent
     * adjusts on different members would lock the same `StockItem` rows in
     * opposite orders and deadlock. Sorting by a stable composite key (morph class
     * + primary key) makes the lock-acquisition order independent of which member
     * triggered the adjust, so the locks always stack the same way. Primary-target
     * detection moves to identity ({@see __invoke} uses `$stockable->is($target)`)
     * since the entry member is no longer guaranteed to be first.
     *
     * @param  list<Model&HasStock>  $targets
     * @return list<Model&HasStock>
     */
    private function orderForLocking(array $targets): array
    {
        usort(
            $targets,
            static fn (Model $a, Model $b): int => [$a->getMorphClass(), $a->getKey()] <=> [$b->getMorphClass(), $b->getKey()],
        );

        return $targets;
    }

    /**
     * Acquire a row-locked StockItem for the given stockable, creating it if absent.
     *
     * The lock serializes concurrent adjustments for the same stockable so that
     * callers doing read-then-write (e.g. availability check → reserve) see a
     * consistent stock level and cannot oversell.
     */
    private function findOrCreateStockItem(Model&HasStock $stockable): StockItem
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

    private function createMovement(
        StockItem $stockItem,
        int $quantity,
        StockMovementReason $reason,
        ?string $description,
        ?Model $reference,
        ?string $source,
    ): StockMovement {
        $attributes = [
            'quantity' => $quantity,
            'reason' => $reason,
            'description' => $description,
            'source' => $source,
        ];

        if ($reference !== null) {
            $attributes['reference_type'] = $reference->getMorphClass();
            $attributes['reference_id'] = $reference->getKey();
        }

        return $stockItem->movements()->create($attributes);
    }

    private function updateStockLevel(StockItem $stockItem, int $quantity): void
    {
        // Read-then-write under the lockForUpdate held by findOrCreateStockItem.
        // Was `->increment('stock_level', $quantity)` (a single atomic UPDATE),
        // but now that stock_level is cast to Stock-only on write, the new
        // value has to be constructed explicitly. The surrounding row lock
        // serializes the read+write window, so concurrent adjusts can't lose.
        $stockItem->stock_level = new Stock($stockItem->stock_level + $quantity);
        $stockItem->save();
    }

    private function validateSource(?string $source): void
    {
        if ($source === null) {
            return;
        }

        $configured = config('inventory.sources');

        if ($configured === null || $configured === []) {
            return;
        }

        if (! array_key_exists($source, $configured)) {
            throw new InvalidArgumentException(
                "Invalid stock movement source [{$source}]. Allowed: ".implode(', ', array_keys($configured)).'.',
            );
        }
    }
}
