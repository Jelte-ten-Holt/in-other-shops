<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Filament\Resources\VoucherResource\Pages;

use InOtherShops\Pricing\Filament\Resources\VoucherResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

final class EditVoucher extends EditRecord
{
    protected static string $resource = VoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
