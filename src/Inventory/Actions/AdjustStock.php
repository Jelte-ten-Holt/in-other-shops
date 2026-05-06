<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Actions;

use InOtherShops\Inventory\Contracts\HasStock;
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

        $targets = $this->resolveTargets($stockable);

        $results = DB::transaction(function () use ($targets, $quantity, $reason, $description, $reference, $source): array {
            $out = [];
            foreach ($targets as $target) {
                $stockItem = $this->findOrCreateStockItem($target);
                $movement = $this->createMovement($stockItem, $quantity, $reason, $description, $reference, $source);
                $this->updateStockLevel($stockItem, $quantity);

                $out[] = [$movement, $stockItem->refresh(), $target === $targets[0]];
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
            return $stockable->stockItem()->create(['stock_level' => 0]);
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
        $stockItem->increment('stock_level', $quantity);
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
