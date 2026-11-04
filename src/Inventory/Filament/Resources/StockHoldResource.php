<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Filament\Resources;

use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InOtherShops\Inventory\Actions\ReleaseHold;
use InOtherShops\Inventory\Contracts\HasStock;
use InOtherShops\Inventory\Filament\Resources\StockHoldResource\Pages;
use InOtherShops\Inventory\Models\StockHold;
use InOtherShops\Inventory\Models\StockReservation;
use InOtherShops\Inventory\Support\StockLocks;
use InOtherShops\Support\Filament\NavigationGroup;
use InOtherShops\Support\Filament\PackageResource;
use InOtherShops\Support\ModelLabel;

/**
 * The Stock holds page (stock-holds brief D7): stock set aside so it cannot sell
 * online — "Markt 15 Nov", "Kept for Ana". List with what each holds, its age
 * and a stale badge; create and edit (each product set to a number, under the
 * lock, by AdjustHold); filter by product; Release.
 *
 * Holds hold products, not bundles (§8 Q1): the product picker lists the
 * models implementing HasStock that track stock. A released hold is read-only.
 *
 * Consumers register it in their panel and map StockHold to the policy that
 * already governs stock (D8): at IOW the products permission, at bianka
 * admin-only. No policy, no access ({@see PackageResource}).
 */
class StockHoldResource extends PackageResource
{
    protected static ?string $model = StockHold::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Inventory;

    protected static function labelKey(): string
    {
        return 'shops-inventory::holds';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make()
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('shops-inventory::holds.fields.name'))
                            ->helperText(__('shops-inventory::holds.fields.name_help'))
                            ->required()
                            ->maxLength(255),
                        DatePicker::make('held_for')
                            ->label(__('shops-inventory::holds.fields.held_for'))
                            ->helperText(__('shops-inventory::holds.fields.held_for_help')),
                        Textarea::make('notes')
                            ->label(__('shops-common::fields.notes'))
                            ->columnSpanFull(),
                    ]),
                Section::make(__('shops-inventory::holds.fields.products'))
                    ->schema([
                        Repeater::make('_lines')
                            ->hiddenLabel()
                            ->schema([
                                Select::make('stockable')
                                    ->label(__('shops-inventory::holds.fields.product'))
                                    ->options(fn (): array => static::stockableOptions())
                                    ->searchable()
                                    ->required()
                                    ->distinct(),
                                TextInput::make('quantity')
                                    ->label(__('shops-common::fields.quantity'))
                                    ->numeric()
                                    ->integer()
                                    ->minValue(0)
                                    ->required()
                                    ->default(1),
                            ])
                            ->columns(2)
                            ->defaultItems(1)
                            ->addActionLabel(__('shops-inventory::holds.actions.add_product'))
                            ->helperText(__('shops-inventory::holds.fields.products_help')),
                        Hidden::make('_loaded_groups')
                            ->default([])
                            ->dehydrated(false),
                    ]),
            ])
            ->disabled(fn (?StockHold $record): bool => $record?->isReleased() ?? false);
    }

    public static function table(Table $table): Table
    {
        return $table
            // What each hold holds, for the whole page in two queries — never
            // a query per row.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withSum('pendingReservations as held_units', 'quantity')
                ->with('pendingReservations.stockItem.stockable'))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('shops-inventory::holds.fields.name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('held_for')
                    ->label(__('shops-inventory::holds.fields.held_for'))
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('products')
                    ->label(__('shops-inventory::holds.fields.products'))
                    ->getStateUsing(fn (StockHold $record): ?string => static::productsSummary($record))
                    ->wrap(),
                Tables\Columns\TextColumn::make('held_units')
                    ->label(__('shops-inventory::holds.columns.units'))
                    ->numeric()
                    ->default(0),
                Tables\Columns\TextColumn::make('age')
                    ->label(__('shops-inventory::holds.columns.age'))
                    ->getStateUsing(fn (StockHold $record): string => $record->reservationsOpenSince()->diffForHumans()),
                Tables\Columns\TextColumn::make('state')
                    ->label(__('shops-common::fields.status'))
                    ->badge()
                    ->color(fn (StockHold $record): string => $record->isStale() ? 'warning' : 'gray')
                    ->getStateUsing(fn (StockHold $record): ?string => match (true) {
                        $record->isReleased() => __('shops-inventory::holds.state.released'),
                        $record->isStale() => __('shops-inventory::holds.state.stale'),
                        default => null,
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('released')
                    ->label(__('shops-inventory::holds.filters.released'))
                    ->nullable()
                    ->attribute('released_at')
                    ->default(false),
                Tables\Filters\SelectFilter::make('product')
                    ->label(__('shops-inventory::holds.fields.product'))
                    ->options(fn (): array => static::stockableOptions())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => static::holdingProduct($query, $data['value'] ?? null)),
            ])
            ->actions([
                static::releaseAction(),
                Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStockHolds::route('/'),
            'create' => Pages\CreateStockHold::route('/create'),
            'edit' => Pages\EditStockHold::route('/{record}/edit'),
        ];
    }

    /**
     * Gives back everything the hold holds and ends it. Offered while the hold
     * is open — and on a released hold that still has a row, as the sweep
     * that repairs it.
     */
    public static function releaseAction(): Actions\Action
    {
        return Actions\Action::make('release')
            ->label(__('shops-inventory::holds.actions.release'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription(__('shops-inventory::holds.actions.release_modal'))
            ->authorize('update')
            ->visible(fn (StockHold $record): bool => ! $record->isReleased() || $record->pendingReservations()->exists())
            ->action(function (StockHold $record): void {
                app(ReleaseHold::class)($record);

                Notification::make()
                    ->title(__('shops-inventory::holds.notifications.released', ['name' => $record->name]))
                    ->success()
                    ->send();
            });
    }

    /**
     * "alias:id" => label, for every stock-tracking HasStock model in the
     * morph map. Keyed by alias so two types sharing an id never collide.
     *
     * @return array<string, string>
     */
    public static function stockableOptions(): array
    {
        $options = [];

        foreach (Relation::morphMap() as $alias => $class) {
            if (! is_string($class) || ! is_a($class, HasStock::class, true)) {
                continue;
            }

            foreach ($class::query()->get() as $model) {
                if ($model instanceof HasStock && $model->tracksStock()) {
                    $options["{$alias}:{$model->getKey()}"] = ModelLabel::for($model);
                }
            }
        }

        asort($options);

        return $options;
    }

    public static function resolveStockable(?string $key): (Model&HasStock)|null
    {
        if ($key === null || ! str_contains($key, ':')) {
            return null;
        }

        [$alias, $id] = explode(':', $key, 2);
        $class = Relation::getMorphedModel($alias);
        $model = $class === null ? null : $class::query()->find($id);

        return $model instanceof HasStock ? $model : null;
    }

    public static function stockableKey(Model $stockable): string
    {
        return $stockable->getMorphClass().':'.$stockable->getKey();
    }

    private static function productsSummary(StockHold $hold): ?string
    {
        $lines = [];

        // One entry per stock group, as everywhere a hold is shown or edited.
        foreach ($hold->pendingReservations as $reservation) {
            /** @var StockReservation $reservation */
            $stockable = $reservation->stockItem?->stockable;
            $group = $stockable instanceof HasStock ? StockLocks::groupKey($stockable) : '?';
            $lines[$group] ??= ['label' => $stockable === null ? '?' : ModelLabel::for($stockable), 'quantity' => 0];
            $lines[$group]['quantity'] += (int) $reservation->quantity;
        }

        if ($lines === []) {
            return null;
        }

        return collect($lines)->map(fn (array $line): string => "{$line['quantity']} × {$line['label']}")->implode(', ');
    }

    private static function holdingProduct(Builder $query, ?string $key): Builder
    {
        $stockable = static::resolveStockable($key);

        if ($stockable === null) {
            return $query;
        }

        return $query->whereHas('pendingReservations', fn (Builder $rows) => $rows->whereIn('stock_item_id', StockLocks::stockItemIds($stockable)));
    }
}
