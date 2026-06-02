<?php

declare(strict_types=1);

namespace InOtherShops\Variants\Filament\Resources\OptionResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use InOtherShops\Translation\Filament\TranslationSchema;
use InOtherShops\Variants\Filament\Resources\OptionResource;

final class CreateOption extends CreateRecord
{
    protected static string $resource = OptionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        unset($data['translations'], $data['_values']);

        return $data;
    }

    protected function afterCreate(): void
    {
        TranslationSchema::saveFormData($this->record, $this->data);
        OptionResource::saveValues($this->record, $this->data);
    }
}
