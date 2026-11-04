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
 * form to zero, in the global lock order. A product is a stock group: siblings
 * of a shared-inventory locale group are one product here, as they are to
 * every hold actor. Both pages force their transaction
 * (`$hasDatabaseTransactions = true`),
 * so a product refused partway — more than is in stock, a released hold, a
 * lock conflict — rolls back everything this save did: a notification, a
 * `Halt` with rollback, no half-made hold.
 */
trait SavesHoldLines
{
    /**
     * @param  array<array-key, mixed>  $lines  the `_lines` repeater state
     * @param  list<string>|null  $loadedGroups  edit: the stock groups the form showed when it
     *                                           loaded; one no longer in the form goes to zero.
     *                                           A product that reached the hold since (an
     *                                           order's units coming back) is left alone.
     */
    protected function applyHoldLines(StockHold $hold, array $lines, ?array $loadedGroups = null): void
    {
        try {
            $targets = $this->targetsFrom($lines);

            foreach ($this->removedGroups($hold, $targets, $loadedGroups ?? []) as $group => $stockable) {
                $targets[$group] = [$stockable, 0];
            }

            // Every stock item up front, sorted, after the hold row — then each
            // product in that order (§4.2). AdjustHold re-takes the same locks.
            StockLocks::lockHold($hold);
            StockLocks::lock(array_map(fn (array $target): Model => $target[0], array_values($targets)));

            foreach (StockLocks::sorted(array_map(fn (array $target): Model => $target[0], array_values($targets))) as $stockable) {
                app(AdjustHold::class)($hold, $stockable, $targets[StockLocks::groupKey($stockable)][1]);
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
     * One target per stock group: two lines naming siblings of a
     * shared-inventory group add up, because AdjustHold sets the group.
     *
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

            $group = StockLocks::groupKey($stockable);
            $targets[$group] = [$targets[$group][0] ?? $stockable, ($targets[$group][1] ?? 0) + max(0, (int) ($line['quantity'] ?? 0))];
        }

        return $targets;
    }

    /**
     * @param  array<string, array{Model&HasStock, int}>  $targets
     * @param  list<string>  $loadedGroups
     * @return array<string, Model&HasStock>
     */
    private function removedGroups(StockHold $hold, array $targets, array $loadedGroups): array
    {
        $removed = [];

        foreach ($hold->heldLines() as ['stockable' => $stockable]) {
            $group = StockLocks::groupKey($stockable);

            if (! isset($targets[$group]) && in_array($group, $loadedGroups, true)) {
                $removed[$group] = $stockable;
            }
        }

        return $removed;
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
