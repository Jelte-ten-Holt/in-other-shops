<?php

declare(strict_types=1);

namespace InOtherShops\Inventory;

use InOtherShops\Inventory\Commands\ReleaseExpiredReservationsCommand;
use InOtherShops\Inventory\Filament\StockMovementsTable;
use InOtherShops\Inventory\Listeners\InventoryLogSubscriber;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

final class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/inventory.php', 'inventory');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        Relation::morphMap([
            'stock_item' => Inventory::stockItem(),
            'stock_movement' => Inventory::stockMovement(),
            'stock_reservation' => Inventory::stockReservation(),
        ]);

        if ($this->app->bound('livewire')) {
            Livewire::component('inventory-stock-movements-table', StockMovementsTable::class);
        }

        $this->commands([ReleaseExpiredReservationsCommand::class]);

        $this->publishes([
            __DIR__.'/config/inventory.php' => config_path('inventory.php'),
        ], 'inventory-config');

        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);
            $schedule->command('inventory:release-expired')->everyFiveMinutes();
        });

        Event::subscribe(InventoryLogSubscriber::class);
    }
}
