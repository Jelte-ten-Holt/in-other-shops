<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Filament\Resources\StockHoldResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use InOtherShops\Inventory\Filament\Resources\StockHoldResource;
use InOtherShops\Inventory\Filament\Resources\StockHoldResource\SavesHoldLines;
use InOtherShops\Inventory\Models\StockHold;

final class CreateStockHold extends CreateRecord
{
    use SavesHoldLines;

    protected static string $resource = StockHoldResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function afterCreate(): void
    {
        /** @var StockHold $hold */
        $hold = $this->record;

        $this->applyHoldLines($hold, $this->data['_lines'] ?? [], zeroTheRest: false);
    }

    protected function forgetRolledBackRecord(): void
    {
        $this->record = null;
        $this->form->model($this->getModel());
    }
}
