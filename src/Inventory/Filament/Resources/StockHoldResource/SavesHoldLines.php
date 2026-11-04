<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Filament\Resources\StockHoldResource;

use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Database\ConcurrencyErrorDetector as LaravelConcurrencyErrorDetector;
use Illuminate\Database\Eloquent\Model;
use InOtherShops\Inventory\Actions\AdjustHold;
use InOtherShops\Inventory\Contracts\HasStock;
use InOtherShops\Inventory\Exceptions\InventoryException;
use InOtherShops\Inventory\Filament\Resources\StockHoldResource;
use InOtherShops\Inventory\Models\StockHold;
use InOtherShops\Inventory\Support\StockLocks;
use PDOException;

/**
 * The hold pages' save (stock-holds brief §4.1): each product in the form is
 * set to its number under the lock (AdjustHold), a product taken out of the
 * form to zero, in the global lock order. Both pages force their transaction
 * (`$hasDatabaseTransactions = true`),
 * so a product refused partway — more than is in stock, a released hold, a
 * lock conflict — rolls back everything this save did: a notification, a
 * `Halt` with rollback, no half-made hold.
 */
trait SavesHoldLines
{
    /**
     * @param  array<array-key, mixed>  $lines  the `_lines` repeater state
     */
    protected function applyHoldLines(StockHold $hold, array $lines, bool $zeroTheRest): void
    {
        $targets = $this->targetsFrom($lines);

        if ($zeroTheRest) {
            foreach ($hold->heldLines() as ['stockable' => $stockable]) {
                $targets[StockHoldResource::stockableKey($stockable)] ??= [$stockable, 0];
            }
        }

        $sorted = StockLocks::sorted(array_map(fn (array $target): Model => $target[0], array_values($targets)));

        try {
            foreach ($sorted as $stockable) {
                app(AdjustHold::class)($hold, $stockable, $targets[StockHoldResource::stockableKey($stockable)][1]);
            }
        } catch (InventoryException $e) {
            $this->refuseHoldSave($e->getMessage());
        } catch (PDOException $e) {
            if (! $this->isLockConflict($e)) {
                throw $e;
            }

            $this->refuseHoldSave(__('shops-inventory::holds.notifications.try_again'));
        }
    }

    /**
     * @param  array<array-key, mixed>  $lines
     * @return array<string, array{Model&HasStock, int}>
     */
    private function targetsFrom(array $lines): array
    {
        $targets = [];

        foreach ($lines as $line) {
            $stockable = StockHoldResource::resolveStockable(is_array($line) ? ($line['stockable'] ?? null) : null);

            if ($stockable === null) {
                continue;
            }

            $key = StockHoldResource::stockableKey($stockable);
            $targets[$key] = [$stockable, ($targets[$key][1] ?? 0) + max(0, (int) ($line['quantity'] ?? 0))];
        }

        return $targets;
    }

    private function refuseHoldSave(string $reason): never
    {
        Notification::make()
            ->title(__('shops-inventory::holds.notifications.refused'))
            ->body($reason)
            ->danger()
            ->persistent()
            ->send();

        $this->forgetRolledBackRecord();

        throw (new Halt)->rollBackDatabaseTransaction();
    }

    /**
     * Create: the hold row rolls back with the Halt, so the page forgets it.
     * Edit: the record stays; its fields roll back in the database only.
     */
    abstract protected function forgetRolledBackRecord(): void;

    private function isLockConflict(PDOException $e): bool
    {
        $detector = app()->bound(ConcurrencyErrorDetector::class)
            ? app(ConcurrencyErrorDetector::class)
            : new LaravelConcurrencyErrorDetector;

        return $detector->causedByConcurrencyError($e);
    }
}
