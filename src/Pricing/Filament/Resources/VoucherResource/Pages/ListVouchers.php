<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Filament\Resources\VoucherResource\Pages;

use InOtherShops\Pricing\Filament\Resources\VoucherResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

final class ListVouchers extends ListRecords
{
    protected static string $resource = VoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
