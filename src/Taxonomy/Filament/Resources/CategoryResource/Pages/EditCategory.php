<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Filament\Resources\CategoryResource\Pages;

use InOtherShops\Taxonomy\Filament\Resources\CategoryResource;
use InOtherShops\Translation\Filament\TranslationSchema;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

final class EditCategory extends EditRecord
{
    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->disabled(fn (): bool => $this->record->children()->exists()),
        ];
    }

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
