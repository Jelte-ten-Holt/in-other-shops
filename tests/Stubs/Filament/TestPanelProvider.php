<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs\Filament;

use Filament\Panel;
use Filament\PanelProvider;

/**
 * A bare default Filament panel for the tests that boot Filament (mix in
 * {@see \InOtherShops\Tests\Support\BootsFilament}). The package ships no
 * panel of its own — consumers register theirs — so this one exists to pin the
 * auth guard for {@see \Filament\Facades\Filament::auth()} and to mount the
 * stub Resources under `tests/Stubs/Filament` that the page-level tests drive.
 * It discovers nothing; every Resource is listed explicitly.
 */
final class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('test')
            ->default()
            ->path('test-admin')
            ->authGuard('web')
            ->resources([
                StubEditableResource::class,
            ]);
    }
}
