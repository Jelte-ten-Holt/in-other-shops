<?php

declare(strict_types=1);

namespace InOtherShops\Tax\Filament\Resources\TaxRateResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use InOtherShops\Tax\Filament\Resources\TaxRateResource;

final class CreateTaxRate extends CreateRecord
{
    protected static string $resource = TaxRateResource::class;
}
