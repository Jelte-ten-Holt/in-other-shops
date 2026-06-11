<?php

declare(strict_types=1);

namespace InOtherShops\Tax\Filament\Resources\TaxRateResource\Pages;

use InOtherShops\Support\Filament\PackageEditRecord;
use InOtherShops\Tax\Filament\Resources\TaxRateResource;

final class EditTaxRate extends PackageEditRecord
{
    protected static string $resource = TaxRateResource::class;

}
