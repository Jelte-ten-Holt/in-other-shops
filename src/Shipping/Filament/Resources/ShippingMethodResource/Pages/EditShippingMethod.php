<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Filament\Resources\ShippingMethodResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use InOtherShops\Shipping\Filament\Resources\ShippingMethodResource;

final class EditShippingMethod extends EditRecord
{
    protected static string $resource = ShippingMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
