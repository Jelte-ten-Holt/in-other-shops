<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Filament\Resources\OrderResource\Pages;

use InOtherShops\Commerce\Filament\Resources\OrderResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

final class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
