<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Filament\Resources\TagResource\Pages;

use InOtherShops\Taxonomy\Filament\Resources\TagResource;
use InOtherShops\Support\Filament\PackageListRecords;

final class ListTags extends PackageListRecords
{
    protected static string $resource = TagResource::class;

}
