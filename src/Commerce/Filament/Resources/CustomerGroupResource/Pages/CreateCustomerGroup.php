<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Filament\Resources\CustomerGroupResource\Pages;

use InOtherShops\Commerce\Filament\Resources\CustomerGroupResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateCustomerGroup extends CreateRecord
{
    protected static string $resource = CustomerGroupResource::class;
}
