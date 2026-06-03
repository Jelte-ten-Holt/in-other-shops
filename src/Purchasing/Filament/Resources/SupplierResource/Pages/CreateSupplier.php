<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Filament\Resources\SupplierResource\Pages;

use InOtherShops\Purchasing\Filament\Resources\SupplierResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateSupplier extends CreateRecord
{
    protected static string $resource = SupplierResource::class;
}
