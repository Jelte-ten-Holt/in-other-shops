<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Filament\Resources\CustomerGroupResource\Pages;

use InOtherShops\Commerce\Filament\Resources\CustomerGroupResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

final class EditCustomerGroup extends EditRecord
{
    protected static string $resource = CustomerGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
