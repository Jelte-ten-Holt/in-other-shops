<?php

declare(strict_types=1);

namespace InOtherShops\Tax\Filament\Resources\TaxRateResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use InOtherShops\Tax\Filament\Resources\TaxRateResource;

final class ListTaxRates extends ListRecords
{
    protected static string $resource = TaxRateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
