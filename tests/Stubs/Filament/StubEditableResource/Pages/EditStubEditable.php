<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs\Filament\StubEditableResource\Pages;

use Filament\Resources\Pages\EditRecord;
use InOtherShops\Inventory\Filament\InventorySchema;
use InOtherShops\Media\Filament\MediaSchema;
use InOtherShops\Support\Filament\SyncsManualFormState;
use InOtherShops\Tests\Stubs\Filament\StubEditableResource;
use InOtherShops\Translation\Filament\TranslationSchema;

/**
 * The edit half of the fixture, wired the way a consumer's Edit page is
 * (in-other-worlds `EditProduct`, bianka-shop-one `EditProduct`): fill the
 * three schemas before the form hydrates, remember the dehydrated state on the
 * way to the model write, replay it to the schemas after.
 *
 * This is the reference implementation of the refill convention: no
 * `getRedirectUrl()` override (the page stays on itself after a save, so the
 * Livewire form state survives into the next save — the condition both
 * consumer bugs needed), and `afterSave()` ends with
 * `refillManualFormState()`, which is what makes that survival harmless.
 */
final class EditStubEditable extends EditRecord
{
    use SyncsManualFormState;

    protected static string $resource = StubEditableResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->record->load('translations');

        $data = array_merge($data, TranslationSchema::fillFormData($this->record));
        $data = MediaSchema::fillFormData($this->record, $data);

        return InventorySchema::fillFormData($this->record, $data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->rememberDehydratedFormState($data);

        unset($data['translations'], $data['_media'], $data['_stock']);

        return $data;
    }

    protected function afterSave(): void
    {
        $state = $this->manualFormState();

        TranslationSchema::saveFormData($this->record, $state);
        MediaSchema::saveFormData($this->record, $state);
        InventorySchema::saveFormData($this->record, $state);

        // Last, always: the form now shows what a fresh load would.
        $this->refillManualFormState();
    }
}
