<?php

declare(strict_types=1);

namespace InOtherShops\Variants\Filament\Resources\OptionResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use InOtherShops\Support\Filament\FormSync;
use InOtherShops\Support\Filament\SavesTranslatableForm;
use InOtherShops\Translation\Filament\TranslationSchema;
use InOtherShops\Variants\Filament\Resources\OptionResource;

final class CreateOption extends CreateRecord
{
    use SavesTranslatableForm;

    protected static string $resource = OptionResource::class;

    protected function syncSchemas(): array
    {
        return [
            new FormSync(
                keys: ['translations'],
                fill: null,
                save: fn ($record, array $data) => TranslationSchema::saveFormData($record, $data),
            ),
            new FormSync(
                keys: ['_values'],
                fill: null,
                save: fn ($record, array $data) => OptionResource::saveValues($record, $data),
            ),
        ];
    }
}
