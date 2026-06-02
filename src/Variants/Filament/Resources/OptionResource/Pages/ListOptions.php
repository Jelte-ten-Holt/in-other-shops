<?php

declare(strict_types=1);

namespace InOtherShops\Variants\Filament\Resources\OptionResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use InOtherShops\Variants\Filament\Resources\OptionResource;

final class ListOptions extends ListRecords
{
    protected static string $resource = OptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
