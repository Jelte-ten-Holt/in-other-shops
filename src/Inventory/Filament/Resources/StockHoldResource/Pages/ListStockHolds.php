<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Filament\Resources\StockHoldResource\Pages;

use InOtherShops\Inventory\Filament\Resources\StockHoldResource;
use InOtherShops\Support\Filament\PackageListRecords;

final class ListStockHolds extends PackageListRecords
{
    protected static string $resource = StockHoldResource::class;
}
