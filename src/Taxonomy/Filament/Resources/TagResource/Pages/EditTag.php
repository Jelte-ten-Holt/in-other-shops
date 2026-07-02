<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Filament\Resources\TagResource\Pages;

use InOtherShops\Support\Filament\FormSync;
use InOtherShops\Support\Filament\PackageEditRecord;
use InOtherShops\Support\Filament\SavesTranslatableForm;
use InOtherShops\Taxonomy\Filament\Resources\TagResource;
use InOtherShops\Translation\Filament\TranslationSchema;

final class EditTag extends PackageEditRecord
{
    use SavesTranslatableForm;

    protected static string $resource = TagResource::class;

    protected function syncSchemas(): array
    {
        return [
            new FormSync(
                keys: ['translations'],
                fill: fn ($record, array $data) => array_merge($data, TranslationSchema::fillFormData($record->load('translations'))),
                save: fn ($record, array $data) => TranslationSchema::saveFormData($record, $data),
            ),
        ];
    }
}
