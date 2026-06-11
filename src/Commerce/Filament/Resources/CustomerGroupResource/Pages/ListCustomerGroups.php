<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Filament\Resources\CustomerGroupResource\Pages;

use InOtherShops\Commerce\Filament\Resources\CustomerGroupResource;
use InOtherShops\Support\Filament\PackageListRecords;

final class ListCustomerGroups extends PackageListRecords
{
    protected static string $resource = CustomerGroupResource::class;

}
