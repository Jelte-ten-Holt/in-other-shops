<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Filament\Resources\PurchaseOrderResource\Pages;

use InOtherShops\Purchasing\Filament\Resources\PurchaseOrderResource;
use InOtherShops\Support\Filament\PackageEditRecord;

final class EditPurchaseOrder extends PackageEditRecord
{
    protected static string $resource = PurchaseOrderResource::class;

}
