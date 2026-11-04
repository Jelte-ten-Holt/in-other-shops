<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Filament\Resources\StockHoldResource\Pages;

use InOtherShops\Support\Filament\PackageEditRecord;
use InOtherShops\Inventory\Filament\Resources\StockHoldResource;
use InOtherShops\Inventory\Filament\Resources\StockHoldResource\SavesHoldLines;
use InOtherShops\Inventory\Models\StockHold;
use InOtherShops\Support\Filament\SyncsManualFormState;

/**
 * Editing a hold sets each product to the number in the form, under the lock;
 * a product taken out of the form goes to zero. The form is refilled from what
 * the hold holds after every save (the refill convention), so it never shows
 * a number the save did not land on. A released hold is read-only: no save,
 * and Release stays as the sweep while anything still references it.
 */
final class EditStockHold extends PackageEditRecord
{
    use SavesHoldLines;
    use SyncsManualFormState;

    protected static string $resource = StockHoldResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function getHeaderActions(): array
    {
        return [
            StockHoldResource::releaseAction()
                ->after(fn () => $this->refillManualFormState()),
        ];
    }

    protected function getFormActions(): array
    {
        return $this->hold()->isReleased() ? [$this->getCancelFormAction()] : parent::getFormActions();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['_lines'] = array_map(fn (array $line): array => [
            'stockable' => StockHoldResource::stockableKey($line['stockable']),
            'quantity' => $line['quantity'],
        ], $this->hold()->heldLines());

        return $data;
    }

    protected function afterSave(): void
    {
        $this->applyHoldLines($this->hold(), $this->data['_lines'] ?? [], zeroTheRest: true);

        $this->refillManualFormState();
    }

    protected function forgetRolledBackRecord(): void
    {
        $this->record->refresh();
    }

    private function hold(): StockHold
    {
        /** @var StockHold */
        return $this->record;
    }
}
