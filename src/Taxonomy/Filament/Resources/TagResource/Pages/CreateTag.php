<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Filament\Resources\TagResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use InOtherShops\Support\Filament\FormSync;
use InOtherShops\Support\Filament\SavesTranslatableForm;
use InOtherShops\Taxonomy\Filament\Resources\TagResource;
use InOtherShops\Translation\Filament\TranslationSchema;

final class CreateTag extends CreateRecord
{
    use SavesTranslatableForm;

    protected static string $resource = TagResource::class;

    protected function syncSchemas(): array
    {
        return [
            new FormSync(
                keys: ['translations'],
                fill: null,
                save: fn ($record, array $data) => TranslationSchema::saveFormData($record, $data),
            ),
        ];
    }
}
