<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Filament\Resources\CustomerResource\Pages;

use InOtherShops\Commerce\Filament\Resources\CustomerResource;
use InOtherShops\Support\Filament\PackageListRecords;

final class ListCustomers extends PackageListRecords
{
    protected static string $resource = CustomerResource::class;

}
