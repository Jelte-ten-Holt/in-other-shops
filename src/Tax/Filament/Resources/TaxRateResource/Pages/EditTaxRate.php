<?php

declare(strict_types=1);

namespace InOtherShops\Tax\Filament\Resources\TaxRateResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use InOtherShops\Tax\Filament\Resources\TaxRateResource;

final class EditTaxRate extends EditRecord
{
    protected static string $resource = TaxRateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
