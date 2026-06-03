<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Filament\Resources\SupplierResource\Pages;

use InOtherShops\Purchasing\Filament\Resources\SupplierResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditSupplier extends EditRecord
{
    protected static string $resource = SupplierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
