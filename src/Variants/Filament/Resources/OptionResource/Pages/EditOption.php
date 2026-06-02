<?php

declare(strict_types=1);

namespace InOtherShops\Variants\Filament\Resources\OptionResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use InOtherShops\Translation\Filament\TranslationSchema;
use InOtherShops\Variants\Filament\Resources\OptionResource;

final class EditOption extends EditRecord
{
    protected static string $resource = OptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->record->load('translations');

        $data = array_merge($data, TranslationSchema::fillFormData($this->record));

        return OptionResource::fillValues($this->record, $data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['translations'], $data['_values']);

        return $data;
    }

    protected function afterSave(): void
    {
        TranslationSchema::saveFormData($this->record, $this->data);
        OptionResource::saveValues($this->record, $this->data);
    }
}
