<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Filament\Resources\PurchaseOrderResource\Pages;

use InOtherShops\Purchasing\Filament\Resources\PurchaseOrderResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditPurchaseOrder extends EditRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
