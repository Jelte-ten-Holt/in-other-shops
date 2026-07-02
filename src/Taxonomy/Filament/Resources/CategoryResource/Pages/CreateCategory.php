<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Filament\Resources\CategoryResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use InOtherShops\Media\Filament\MediaSchema;
use InOtherShops\Support\Filament\FormSync;
use InOtherShops\Support\Filament\SavesTranslatableForm;
use InOtherShops\Taxonomy\Filament\Resources\CategoryResource;
use InOtherShops\Translation\Filament\TranslationSchema;

final class CreateCategory extends CreateRecord
{
    use SavesTranslatableForm;

    protected static string $resource = CategoryResource::class;

    protected function syncSchemas(): array
    {
        return [
            new FormSync(
                keys: ['translations'],
                fill: null,
                save: fn ($record, array $data) => TranslationSchema::saveFormData($record, $data),
            ),
            new FormSync(
                keys: ['_media'],
                fill: null,
                save: fn ($record, array $data) => MediaSchema::saveFormData($record, $data),
            ),
        ];
    }
}
