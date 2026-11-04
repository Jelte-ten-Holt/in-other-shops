<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Actions;

use InOtherShops\Inventory\Contracts\HasStock;
use InOtherShops\Inventory\DTOs\Stock;
use InOtherShops\Inventory\Enums\StockMovementReason;
use InOtherShops\Inventory\Events\StockAdjusted;
use InOtherShops\Inventory\Models\StockItem;
use InOtherShops\Inventory\Models\StockMovement;
use InOtherShops\Inventory\Support\StockItemLocks;
use Illuminate\Database\Eloquent\Model;
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

        // The group's rows, in the global lock order (StockItemLocks, G7): the
        // target set is identical for every member of a shared-inventory group,
        // but sorting makes the lock order independent of which member
        // triggered the adjust, so concurrent adjusts stack the same way.
        $targets = StockItemLocks::sorted(StockItemLocks::targets($stockable));

        $results = DB::transaction(function () use ($targets, $stockable, $quantity, $reason, $description, $reference, $source): array {
            $out = [];
            foreach ($targets as $target) {
                $stockItem = StockItemLocks::findOrCreateLocked($target);
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
        // Read-then-write under the lockForUpdate held by StockItemLocks.
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
