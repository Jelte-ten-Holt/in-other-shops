<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Filament\Resources\CustomerResource\Pages;

use InOtherShops\Commerce\Filament\Resources\CustomerResource;
use InOtherShops\Support\Filament\PackageEditRecord;

/**
 * Filament's default record write. The form carries no group select (customer
 * groups are backend-only), so `customer_group_id` is absent from the save
 * payload and the stored assignment survives an unrelated edit untouched.
 */
final class EditCustomer extends PackageEditRecord
{
    protected static string $resource = CustomerResource::class;
}
