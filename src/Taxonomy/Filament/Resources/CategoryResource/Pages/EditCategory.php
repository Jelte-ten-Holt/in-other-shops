<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Filament\Resources\CategoryResource\Pages;

use Filament\Actions;
use InOtherShops\Media\Filament\MediaSchema;
use InOtherShops\Support\Filament\FormSync;
use InOtherShops\Support\Filament\PackageEditRecord;
use InOtherShops\Support\Filament\SavesTranslatableForm;
use InOtherShops\Taxonomy\Filament\Resources\CategoryResource;
use InOtherShops\Translation\Filament\TranslationSchema;

final class EditCategory extends PackageEditRecord
{
    use SavesTranslatableForm;

    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->disabled(fn (): bool => $this->record->children()->exists()),
        ];
    }

    protected function syncSchemas(): array
    {
        return [
            new FormSync(
                keys: ['translations'],
                fill: fn ($record, array $data) => array_merge($data, TranslationSchema::fillFormData($record->load('translations'))),
                save: fn ($record, array $data) => TranslationSchema::saveFormData($record, $data),
            ),
            new FormSync(
                keys: ['_media'],
                fill: fn ($record, array $data) => MediaSchema::fillFormData($record, $data),
                save: fn ($record, array $data) => MediaSchema::saveFormData($record, $data),
            ),
        ];
    }
}
