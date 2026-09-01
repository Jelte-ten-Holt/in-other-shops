<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Support;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use InOtherShops\Tests\Stubs\Filament\TestPanelProvider;
use Livewire\LivewireServiceProvider;

/**
 * Stands up the Filament runtime for a test class.
 *
 * The package never boots Filament in its suite by default — the Schemas and
 * Resources it ships are exercised through their static methods, and the
 * ordinary test is faster without Livewire. A test that needs the real thing
 * (Resource authorization through Filament's container-bound manager, or a
 * Resource page driven through {@see \Livewire\Livewire::test()}) mixes this
 * in: it appends the Filament/Livewire providers and the bare
 * {@see TestPanelProvider}, which registers the stub Resources under
 * `tests/Stubs/Filament`.
 *
 * `parent::` inside a trait resolves against the using class's parent, so this
 * composes with {@see \InOtherShops\Tests\TestCase::getPackageProviders()}.
 */
trait BootsFilament
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Livewire signs every component snapshot with the app key; Testbench
        // ships without one, and the first render dies on MissingAppKeyException.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }

    protected function getPackageProviders($app): array
    {
        // Order is load-bearing and mirrors package discovery in a real app
        // (alphabetical: blade-ui-kit, filament/*, livewire). Filament's
        // SupportServiceProvider `bind()`s Livewire's DataStore to its own
        // override during register(); Livewire's register() then resolves
        // that binding once and pins it as the singleton instance. Register
        // Livewire first and Filament's transient bind replaces the singleton
        // afterwards — every store() call gets a fresh, empty store, and the
        // first component render dies on a null error bag.
        return [
            ...parent::getPackageProviders($app),
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            ActionsServiceProvider::class,
            FilamentServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            SupportServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            LivewireServiceProvider::class,
            TestPanelProvider::class,
        ];
    }
}
