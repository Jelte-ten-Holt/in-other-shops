<?php

declare(strict_types=1);

namespace InOtherShops\Support\Filament;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * Base for the package's resource edit pages: a Delete header action by
 * default. Pages with richer headers (EditOrder's refund actions,
 * EditCategory's children-guarded delete) override getHeaderActions().
 */
abstract class PackageEditRecord extends EditRecord
{
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
