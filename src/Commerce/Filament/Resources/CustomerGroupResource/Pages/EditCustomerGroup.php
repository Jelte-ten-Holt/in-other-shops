<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Filament\Resources\CustomerGroupResource\Pages;

use InOtherShops\Commerce\Filament\Resources\CustomerGroupResource;
use InOtherShops\Support\Filament\PackageEditRecord;

final class EditCustomerGroup extends PackageEditRecord
{
    protected static string $resource = CustomerGroupResource::class;

}
