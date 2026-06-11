<?php

declare(strict_types=1);

namespace InOtherShops\Support\Filament;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * Base for the package's resource list pages: every one ships a Create
 * header action and nothing else. A page needing different headers
 * overrides getHeaderActions() — the base is a default, not a cage.
 */
abstract class PackageListRecords extends ListRecords
{
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
