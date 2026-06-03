<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Filament\Resources\SupplierResource\Pages;

use InOtherShops\Purchasing\Filament\Resources\SupplierResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListSuppliers extends ListRecords
{
    protected static string $resource = SupplierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
