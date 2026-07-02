<?php

declare(strict_types=1);

namespace InOtherShops\Variants\Filament\Resources\OptionResource\Pages;

use InOtherShops\Support\Filament\FormSync;
use InOtherShops\Support\Filament\PackageEditRecord;
use InOtherShops\Support\Filament\SavesTranslatableForm;
use InOtherShops\Translation\Filament\TranslationSchema;
use InOtherShops\Variants\Filament\Resources\OptionResource;

final class EditOption extends PackageEditRecord
{
    use SavesTranslatableForm;

    protected static string $resource = OptionResource::class;

    protected function syncSchemas(): array
    {
        return [
            new FormSync(
                keys: ['translations'],
                fill: fn ($record, array $data) => array_merge($data, TranslationSchema::fillFormData($record->load('translations'))),
                save: fn ($record, array $data) => TranslationSchema::saveFormData($record, $data),
            ),
            new FormSync(
                keys: ['_values'],
                fill: fn ($record, array $data) => OptionResource::fillValues($record, $data),
                save: fn ($record, array $data) => OptionResource::saveValues($record, $data),
            ),
        ];
    }
}
