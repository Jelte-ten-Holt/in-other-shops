<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Filament\Resources\PurchaseOrderResource\Pages;

use InOtherShops\Purchasing\Filament\Resources\PurchaseOrderResource;
use InOtherShops\Support\Filament\PackageListRecords;

final class ListPurchaseOrders extends PackageListRecords
{
    protected static string $resource = PurchaseOrderResource::class;

}
