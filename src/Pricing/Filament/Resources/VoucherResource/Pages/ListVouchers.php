<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Filament\Resources\VoucherResource\Pages;

use InOtherShops\Pricing\Filament\Resources\VoucherResource;
use InOtherShops\Support\Filament\PackageListRecords;

final class ListVouchers extends PackageListRecords
{
    protected static string $resource = VoucherResource::class;

}
