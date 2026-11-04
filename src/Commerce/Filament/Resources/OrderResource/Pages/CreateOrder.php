<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Filament\Resources\OrderResource\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Database\ConcurrencyErrorDetector as LaravelConcurrencyErrorDetector;
use Illuminate\Validation\ValidationException;
use InOtherShops\Commerce\Exceptions\StockHoldChangedException;
use InOtherShops\Commerce\Filament\OrderStockPreview;
use InOtherShops\Commerce\Filament\Resources\OrderResource;
use InOtherShops\Commerce\Order\Actions\ReserveOrderStock;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Inventory\Exceptions\InsufficientStockException;
use InOtherShops\Inventory\Exceptions\StockHoldReleasedException;
use InOtherShops\Inventory\Models\StockHold;
use PDOException;

/**
 * Creating an order in the admin takes its stock (stock-holds brief §4.3): from
 * main stock, from a hold, or not at all, as the lines tab says.
 *
 * Availability is checked twice. Before saving, each line's shortfall is a
 * field error on that line ({@see OrderStockPreview}). Under the locks, in
 * `afterCreate()` once the lines are saved, ReserveOrderStock takes the stock;
 * a lost race (the shop sold the last piece in between), a hold that changed
 * since its split was shown, or a lock conflict (SQLSTATE 40001; MySQL 1213,
 * 1205 — Laravel's ConcurrencyErrorDetector) ends in
 * a notification and a `Halt` with rollback — nothing created, no error dialog.
 *
 * The page forces its own transaction, so the order, its lines and its
 * reservations commit together whatever the panel's setting.
 */
final class CreateOrder extends CreateRecord
{
    protected static string $resource = OrderResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    /** Set by a refused save; the snapshot is retaken once its transaction has rolled back. */
    private bool $retakeHoldSnapshot = false;

    protected function beforeCreate(): void
    {
        $this->ensureStockCovers();
    }

    protected function afterCreate(): void
    {
        $this->takeStock();
    }

    public function rendering(): void
    {
        if (! $this->retakeHoldSnapshot) {
            return;
        }

        $this->retakeHoldSnapshot = false;
        $this->data['_stock_held'] = $this->hold()?->heldByGroup() ?? [];
    }

    private function ensureStockCovers(): void
    {
        if ($this->createdCancelled()) {
            return;
        }

        $hold = $this->hold();

        $errors = OrderStockPreview::analyse(
            lines: $this->data['lines'] ?? [],
            mode: $this->stockMode(),
            held: $hold === null ? [] : ($this->data['_stock_held'] ?? []),
            holdName: $hold === null ? null : OrderStockPreview::holdLabel($hold),
        )['errors'];

        if ($errors === []) {
            return;
        }

        throw ValidationException::withMessages(collect($errors)
            ->mapWithKeys(fn (string $message, string $key): array => ["data.lines.{$key}.quantity" => $message])
            ->all());
    }

    private function takeStock(): void
    {
        $mode = $this->stockMode();

        if ($mode === OrderStockPreview::NO_STOCK) {
            return;
        }

        $hold = $this->hold();

        if ($mode === OrderStockPreview::FROM_HOLD && $hold === null) {
            $this->refuse(__('shops-commerce::orders.stock.notifications.hold_missing'));
        }

        try {
            app(ReserveOrderStock::class)(
                $this->record,
                $hold,
                $hold === null ? null : ($this->data['_stock_held'] ?? []),
            );
        } catch (InsufficientStockException|StockHoldReleasedException $e) {
            $this->refuse($e->getMessage());
        } catch (StockHoldChangedException $e) {
            // Show the hold as it is now, so the next save checks against it —
            // read after the rollback (rendering()), not inside this
            // transaction, whose snapshot may predate the change.
            $this->retakeHoldSnapshot = true;
            $this->refuse($e->getMessage());
        } catch (PDOException $e) {
            // A QueryException, or the DeadlockException Laravel rethrows it
            // as inside a nested transaction — both PDOExceptions.
            if (! self::isLockConflict($e)) {
                throw $e;
            }

            $this->refuse(__('shops-commerce::orders.stock.notifications.try_again'));
        }
    }

    /**
     * Ends the request quietly with nothing created. The order row is rolled
     * back with the Halt, so the page forgets it and is a create page again:
     * a second save starts fresh.
     */
    private function refuse(string $reason): never
    {
        Notification::make()
            ->title(__('shops-commerce::orders.stock.notifications.refused'))
            ->body($reason)
            ->danger()
            ->persistent()
            ->send();

        $this->record = null;
        $this->form->model($this->getModel());

        throw (new Halt)->rollBackDatabaseTransaction();
    }

    private function stockMode(): string
    {
        return (string) ($this->data['_stock_mode'] ?? OrderStockPreview::FROM_STOCK);
    }

    private function hold(): ?StockHold
    {
        if ($this->stockMode() !== OrderStockPreview::FROM_HOLD || blank($this->data['_stock_hold_id'] ?? null)) {
            return null;
        }

        return StockHold::query()->find($this->data['_stock_hold_id']);
    }

    private function createdCancelled(): bool
    {
        $status = $this->data['status'] ?? null;

        return ($status instanceof OrderStatus ? $status : OrderStatus::tryFrom((string) $status)) === OrderStatus::Cancelled;
    }

    /**
     * Laravel's own test: SQLSTATE 40001, a deadlock (MySQL 1213) or a
     * lock-wait timeout (1205), among others.
     */
    private static function isLockConflict(PDOException $e): bool
    {
        $detector = app()->bound(ConcurrencyErrorDetector::class)
            ? app(ConcurrencyErrorDetector::class)
            : new LaravelConcurrencyErrorDetector;

        return $detector->causedByConcurrencyError($e);
    }
}
