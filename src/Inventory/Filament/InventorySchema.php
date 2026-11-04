<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Filament;

use InOtherShops\Inventory\Actions\AdjustStock;
use InOtherShops\Inventory\Contracts\HasStock;
use InOtherShops\Inventory\Enums\StockMovementReason;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use InOtherShops\Inventory\Filament\Resources\StockHoldResource;
use InOtherShops\Inventory\Models\StockHold;

final class InventorySchema
{
    /**
     * @return array<\Filament\Schemas\Components\Component>
     */
    public static function stockFields(): array
    {
        return [
            TextInput::make('_stock.stock_level')
                ->label(__('shops-inventory::stock.fields.current_stock'))
                ->disabled()
                ->numeric()
                ->default(0)
                ->visibleOn('edit'),
            self::heldLine(),
            TextInput::make('_stock.low_stock_threshold')
                ->label(__('shops-inventory::stock.fields.low_stock_threshold'))
                ->numeric()
                ->minValue(0)
                ->nullable(),
            Section::make(__('shops-inventory::stock.section.adjust_stock'))
                ->schema([
                    TextInput::make('_stock.adjustment_quantity')
                        ->label(__('shops-common::fields.quantity'))
                        ->helperText(__('shops-inventory::stock.fields.adjustment_quantity_help'))
                        ->numeric()
                        ->integer(),
                    Select::make('_stock.adjustment_reason')
                        ->label(__('shops-inventory::stock.fields.reason'))
                        ->options(self::adjustmentReasonOptions()),
                    TextInput::make('_stock.adjustment_description')
                        ->label(__('shops-common::fields.description'))
                        ->maxLength(255),
                ])
                ->collapsed()
                ->columns(3),
        ];
    }

    public static function stockSection(): Section
    {
        return Section::make(__('shops-inventory::stock.section.inventory'))
            ->schema(self::stockFields())
            ->headerActions([
                self::viewMovementsAction(),
            ]);
    }

    /**
     * "Held: 3 (Markt 15 Nov: 2, Kept for Ana: 1)" — the open holds keeping
     * this product off sale, each linking to its hold when the Stock holds page
     * is in the panel (stock-holds brief D7). Read when shown, never stored.
     */
    private static function heldLine(): Placeholder
    {
        return Placeholder::make('_stock.held')
            ->label(__('shops-inventory::stock.fields.held'))
            ->content(fn (?Model $record): ?HtmlString => $record instanceof HasStock ? self::heldSummary($record) : null)
            ->visible(fn (?Model $record): bool => $record instanceof HasStock && StockHold::holding($record) !== []);
    }

    private static function heldSummary(Model&HasStock $record): ?HtmlString
    {
        $holding = StockHold::holding($record);

        if ($holding === []) {
            return null;
        }

        $linked = in_array(StockHoldResource::class, Filament::getCurrentPanel()?->getResources() ?? [], true);

        $holds = array_map(function (array $held) use ($linked): string {
            $label = e($held['hold']->name).': '.$held['quantity'];

            return $linked
                ? '<a href="'.e(StockHoldResource::getUrl('edit', ['record' => $held['hold']])).'" class="underline">'.$label.'</a>'
                : $label;
        }, $holding);

        return new HtmlString(__('shops-inventory::stock.fields.held_summary', [
            'total' => array_sum(array_column($holding, 'quantity')),
            'holds' => implode(', ', $holds),
        ]));
    }

    private static function viewMovementsAction(): Action
    {
        return Action::make('viewStockMovements')
            ->label(__('shops-inventory::stock.actions.movement_history'))
            ->icon('heroicon-o-clock')
            ->modalHeading(__('shops-inventory::stock.actions.movement_history_heading'))
            ->modalWidth(Width::FourExtraLarge)
            ->modalContent(fn (Model $record) => view('shops-inventory::stock-movements-modal', [
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
            source: 'dashboard',
        );
    }

    /**
     * @return array<string, string>
     */
    private static function adjustmentReasonOptions(): array
    {
        return [
            StockMovementReason::Restock->value => StockMovementReason::Restock->label(),
            StockMovementReason::Received->value => StockMovementReason::Received->label(),
            StockMovementReason::Sold->value => StockMovementReason::Sold->label(),
            StockMovementReason::Adjusted->value => StockMovementReason::Adjusted->label(),
        ];
    }
}
