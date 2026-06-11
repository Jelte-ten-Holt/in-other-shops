<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Filament\Resources\CategoryResource\Pages;

use InOtherShops\Taxonomy\Filament\Resources\CategoryResource;
use InOtherShops\Support\Filament\PackageListRecords;

final class ListCategories extends PackageListRecords
{
    protected static string $resource = CategoryResource::class;

}
