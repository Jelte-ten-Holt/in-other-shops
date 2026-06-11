<?php

declare(strict_types=1);

namespace InOtherShops\Variants\Filament\Resources\OptionResource\Pages;

use InOtherShops\Support\Filament\PackageListRecords;
use InOtherShops\Variants\Filament\Resources\OptionResource;

final class ListOptions extends PackageListRecords
{
    protected static string $resource = OptionResource::class;

}
