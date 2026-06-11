<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Filament\Resources\TagResource\Pages;

use InOtherShops\Taxonomy\Filament\Resources\TagResource;
use InOtherShops\Translation\Filament\TranslationSchema;
use InOtherShops\Support\Filament\PackageEditRecord;

final class EditTag extends PackageEditRecord
{
    protected static string $resource = TagResource::class;


    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->record->load('translations');

        return array_merge($data, TranslationSchema::fillFormData($this->record));
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['translations']);

        return $data;
    }

    protected function afterSave(): void
    {
        TranslationSchema::saveFormData($this->record, $this->data);
    }
}
