<?php

declare(strict_types=1);

namespace InOtherShops\Tax\Filament\Resources\TaxRateResource\Pages;

use InOtherShops\Support\Filament\PackageListRecords;
use InOtherShops\Tax\Filament\Resources\TaxRateResource;

final class ListTaxRates extends PackageListRecords
{
    protected static string $resource = TaxRateResource::class;

}
