<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Filament\Resources\SupplierResource\Pages;

use InOtherShops\Purchasing\Filament\Resources\SupplierResource;
use InOtherShops\Support\Filament\PackageListRecords;

final class ListSuppliers extends PackageListRecords
{
    protected static string $resource = SupplierResource::class;

}
