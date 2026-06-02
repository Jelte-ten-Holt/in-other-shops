<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Filament\Resources\CategoryResource\Pages;

use InOtherShops\Media\Filament\MediaSchema;
use InOtherShops\Taxonomy\Filament\Resources\CategoryResource;
use InOtherShops\Translation\Filament\TranslationSchema;
use Filament\Resources\Pages\CreateRecord;

final class CreateCategory extends CreateRecord
{
    protected static string $resource = CategoryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        unset($data['translations'], $data['_media']);

        return $data;
    }

    protected function afterCreate(): void
    {
        TranslationSchema::saveFormData($this->record, $this->data);
        MediaSchema::saveFormData($this->record, $this->data);
    }
}
