<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Filament\Resources\VoucherResource\Pages;

use InOtherShops\Pricing\Filament\Resources\VoucherResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateVoucher extends CreateRecord
{
    protected static string $resource = VoucherResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['code'] = strtoupper($data['code']).'-'.$this->randomSuffix();

        return $data;
    }

    private function randomSuffix(): string
    {
        return implode('', array_map(fn () => chr(random_int(65, 90)), range(1, 4)));
    }
}
