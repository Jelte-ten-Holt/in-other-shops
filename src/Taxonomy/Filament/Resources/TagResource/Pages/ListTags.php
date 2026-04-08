<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Filament\Resources\TagResource\Pages;

use InOtherShops\Taxonomy\Filament\Resources\TagResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

final class ListTags extends ListRecords
{
    protected static string $resource = TagResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
