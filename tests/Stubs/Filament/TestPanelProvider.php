<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs\Filament;

use Filament\Panel;
use Filament\PanelProvider;

/**
 * A bare default Filament panel so {@see \Filament\Facades\Filament::auth()}
 * resolves during authorization tests. The package ships no panel of its own
 * (consumers register theirs), so tests that exercise Resource authorization
 * register this one. It discovers nothing and only pins the auth guard.
 */
final class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('test')
            ->default()
            ->authGuard('web');
    }
}
