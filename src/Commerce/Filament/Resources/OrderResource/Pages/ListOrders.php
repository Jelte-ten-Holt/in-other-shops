<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Filament\Resources\OrderResource\Pages;

use InOtherShops\Commerce\Filament\Resources\OrderResource;
use InOtherShops\Support\Filament\PackageListRecords;

final class ListOrders extends PackageListRecords
{
    protected static string $resource = OrderResource::class;

}
