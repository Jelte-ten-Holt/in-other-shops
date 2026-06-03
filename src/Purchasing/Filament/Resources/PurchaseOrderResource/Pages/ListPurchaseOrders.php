<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Filament\Resources\PurchaseOrderResource\Pages;

use InOtherShops\Purchasing\Filament\Resources\PurchaseOrderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListPurchaseOrders extends ListRecords
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
