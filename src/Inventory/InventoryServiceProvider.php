<?php

declare(strict_types=1);

namespace InOtherShops\Inventory;

use Illuminate\Console\Scheduling\Schedule;
use InOtherShops\Inventory\Commands\ReconcileStockCommand;
use InOtherShops\Inventory\Commands\ReleaseExpiredReservationsCommand;
use InOtherShops\Inventory\Filament\StockMovementsTable;
use InOtherShops\Inventory\Listeners\InventoryLogSubscriber;
use InOtherShops\Support\DomainServiceProvider;
use Livewire\Livewire;

final class InventoryServiceProvider extends DomainServiceProvider
{
    protected function domainDir(): string
    {
        return __DIR__;
    }

    protected function morphAliases(): array
    {
        return [
            'stock_item' => Inventory::stockItem(),
            'stock_movement' => Inventory::stockMovement(),
            'stock_reservation' => Inventory::stockReservation(),
        ];
    }

    protected function logSubscriber(): ?string
    {
        return InventoryLogSubscriber::class;
    }

    protected function domainCommands(): array
    {
        return [ReleaseExpiredReservationsCommand::class, ReconcileStockCommand::class];
    }

    protected function publishesConfig(): bool
    {
        return true;
    }

    public function boot(): void
    {
        parent::boot();

        if ($this->app->bound('livewire')) {
            Livewire::component('inventory-stock-movements-table', StockMovementsTable::class);
        }

        $this->scheduleWhenEnabled(function (Schedule $schedule): void {
            $schedule->command('inventory:release-expired')->everyFiveMinutes();
        });
    }
}
