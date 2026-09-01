<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs\Filament\StubEditableResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use InOtherShops\Inventory\Filament\InventorySchema;
use InOtherShops\Media\Filament\MediaSchema;
use InOtherShops\Support\Filament\SyncsManualFormState;
use InOtherShops\Tests\Stubs\Filament\StubEditableResource;
use InOtherShops\Translation\Filament\TranslationSchema;

/**
 * The create half of the fixture, wired the way a consumer's Create page is
 * (bianka-shop-one `CreateProduct` is the model): strip the manual-sync keys
 * off the model write, remember the dehydrated state, replay it to the
 * schemas after the row exists.
 */
final class CreateStubEditable extends CreateRecord
{
    use SyncsManualFormState;

    protected static string $resource = StubEditableResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->rememberDehydratedFormState($data);

        // `_media` / `_stock` are skipped by Eloquent's fillable check (leading
        // underscore); `translations` is not, and is not a column.
        unset($data['translations'], $data['_media'], $data['_stock']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $state = $this->manualFormState();

        TranslationSchema::saveFormData($this->record, $state);
        MediaSchema::saveFormData($this->record, $state);
        InventorySchema::saveFormData($this->record, $state);
    }
}
