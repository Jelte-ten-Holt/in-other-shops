<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Filament\Resources\OrderResource\Pages;

use InOtherShops\Commerce\Filament\Resources\OrderResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateOrder extends CreateRecord
{
    protected static string $resource = OrderResource::class;
}
