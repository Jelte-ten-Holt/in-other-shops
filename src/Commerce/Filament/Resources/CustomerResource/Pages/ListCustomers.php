<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Filament\Resources\CustomerResource\Pages;

use InOtherShops\Commerce\Filament\Resources\CustomerResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

final class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
