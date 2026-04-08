<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Filament;

use InOtherShops\Inventory\Actions\AdjustStock;
use InOtherShops\Inventory\Contracts\HasStock;
use InOtherShops\Inventory\Enums\StockMovementReason;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;

final class InventorySchema
{
    /**
     * @return array<\Filament\Schemas\Components\Component>
     */
    public static function stockFields(): array
    {
        return [
            TextInput::make('_stock.stock_level')
                ->label('Current stock')
                ->disabled()
                ->numeric()
                ->default(0)
                ->visibleOn('edit'),
            TextInput::make('_stock.low_stock_threshold')
                ->label('Low stock threshold')
                ->numeric()
                ->minValue(0)
                ->nullable(),
            Section::make('Adjust Stock')
                ->schema([
                    TextInput::make('_stock.adjustment_quantity')
                        ->label('Quantity')
                        ->helperText('Positive to add, negative to subtract')
                        ->numeric()
                        ->integer(),
                    Select::make('_stock.adjustment_reason')
                        ->label('Reason')
                        ->options(self::adjustmentReasonOptions()),
                    TextInput::make('_stock.adjustment_description')
                        ->label('Description')
                        ->maxLength(255),
                ])
                ->collapsed()
                ->columns(3),
        ];
    }

    public static function stockSection(): Section
    {
        return Section::make('Inventory')
            ->schema(self::stockFields())
            ->headerActions([
                self::viewMovementsAction(),
            ]);
    }

    private static function viewMovementsAction(): Action
    {
        return Action::make('viewStockMovements')
            ->label('Movement History')
            ->icon('heroicon-o-clock')
            ->modalHeading('Stock Movement History')
            ->modalWidth(Width::FourExtraLarge)
            ->modalContent(fn (Model $record) => view('domains.inventory.stock-movements-modal', [
                'stockableType' => $record->getMorphClass(),
                'stockableId' => $record->getKey(),
            ]))
            ->modalSubmitAction(false)
            ->visible(fn (?Model $record): bool => $record !== null);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillFormData(Model&HasStock $record, array $data): array
    {
        $stockItem = $record->stockItem;

        $data['_stock'] = [
            'stock_level' => $stockItem?->stock_level ?? 0,
            'low_stock_threshold' => $stockItem?->low_stock_threshold,
            'adjustment_quantity' => null,
            'adjustment_reason' => null,
            'adjustment_description' => null,
        ];

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function saveFormData(Model&HasStock $record, array $data): void
    {
        $stockData = $data['_stock'] ?? [];

        self::saveThreshold($record, $stockData);
        self::processAdjustment($record, $stockData);

        $record->unsetRelation('stockItem');
    }

    private static function saveThreshold(Model&HasStock $record, array $stockData): void
    {
        $threshold = isset($stockData['low_stock_threshold'])
            ? (int) $stockData['low_stock_threshold']
            : null;

        $record->stockItem()->updateOrCreate([], [
            'low_stock_threshold' => $threshold,
        ]);
    }

    private static function processAdjustment(Model&HasStock $record, array $stockData): void
    {
        $quantity = $stockData['adjustment_quantity'] ?? null;

        if ($quantity === null || $quantity === '' || (int) $quantity === 0) {
            return;
        }

        $reason = StockMovementReason::tryFrom($stockData['adjustment_reason'] ?? '')
            ?? StockMovementReason::Adjusted;

        $description = $stockData['adjustment_description'] ?? null;

        app(AdjustStock::class)(
            stockable: $record,
            quantity: (int) $quantity,
            reason: $reason,
            description: $description ?: null,
        );
    }

    /**
     * @return array<string, string>
     */
    private static function adjustmentReasonOptions(): array
    {
        return [
            StockMovementReason::Received->value => StockMovementReason::Received->label(),
            StockMovementReason::Sold->value => StockMovementReason::Sold->label(),
            StockMovementReason::Adjusted->value => StockMovementReason::Adjusted->label(),
        ];
    }
}
