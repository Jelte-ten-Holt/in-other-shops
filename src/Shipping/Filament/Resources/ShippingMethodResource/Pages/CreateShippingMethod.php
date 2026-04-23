<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Filament\Resources\ShippingMethodResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use InOtherShops\Shipping\Filament\Resources\ShippingMethodResource;

final class CreateShippingMethod extends CreateRecord
{
    protected static string $resource = ShippingMethodResource::class;
}
