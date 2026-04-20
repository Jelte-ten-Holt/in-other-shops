<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Filament\Resources;

use Filament\Actions;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use InOtherShops\Commerce\Filament\CommerceSchema;
use InOtherShops\Commerce\Filament\Resources\OrderResource\Pages;
use InOtherShops\Commerce\Order\Actions\UpdateOrderStatus;
use InOtherShops\Commerce\Order\Contracts\HasOrders;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Location\Filament\LocationSchema;
use InOtherShops\Payment\Filament\RelationManagers\PaymentsRelationManager;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static string|\UnitEnum|null $navigationGroup = 'Commerce';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Tabs::make('order')
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Details')
                            ->schema(static::orderDetailFields()),
                        Tab::make('Order Lines')
                            ->schema([
                                CommerceSchema::orderLinesRepeater(
                                    orderableModels: static::orderableModels(),
                                    currencyOptions: static::currencyOptions(),
                                )
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, Get $get): void {
                                        static::recalculateOrderTotals($set, $get);
                                    }),
                            ]),
                        Tab::make('Addresses')
                            ->schema([
                                LocationSchema::addressRepeater(),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('order_number')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('customer.name')
                    ->label('Customer')
                    ->placeholder('Guest')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (OrderStatus $state): string => $state->color()),
                Tables\Columns\TextColumn::make('currency')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state->value),
                Tables\Columns\TextColumn::make('shipment.cost')
                    ->label('Shipping')
                    ->formatStateUsing(fn ($record) => $record->shipment?->cost > 0
                        ? $record->currency->format($record->shipment->cost)
                        : '—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('total')
                    ->formatStateUsing(fn ($record) => $record->currency->format($record->total))
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(OrderStatus::class),
            ])
            ->actions([
                static::updateStatusAction(),
                Actions\EditAction::make(),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            PaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'create' => Pages\CreateOrder::route('/create'),
            'edit' => Pages\EditOrder::route('/{record}/edit'),
        ];
    }

    /**
     * Override in your project to register orderable models.
     *
     * @return array<string, class-string<HasOrders>>
     */
    protected static function orderableModels(): array
    {
        return [];
    }

    /**
     * Override in your project to provide currency options.
     *
     * @return array<string, string>
     */
    protected static function currencyOptions(): array
    {
        return [];
    }

    /**
     * @return array<Component>
     */
    protected static function orderDetailFields(): array
    {
        return [
            Select::make('customer_id')
                ->label('Customer')
                ->relationship('customer', 'name')
                ->searchable()
                ->preload()
                ->placeholder('Guest (no customer)'),
            TextInput::make('email')
                ->email()
                ->maxLength(255),
            TextInput::make('order_number')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                ->disabled(fn (?Order $record): bool => $record !== null),
            Select::make('status')
                ->options(OrderStatus::class)
                ->default(OrderStatus::Pending)
                ->required()
                ->disabled(fn (?Order $record): bool => $record !== null),
            ...static::orderCurrencyFields(),
            TextInput::make('subtotal')
                ->numeric()
                ->default('0.00')
                ->minValue(0)
                ->formatStateUsing(fn ($state) => $state !== null ? number_format((int) $state / 100, 2, '.', '') : '0.00')
                ->dehydrateStateUsing(fn ($state) => $state !== null ? (int) round((float) $state * 100) : 0)
                ->live()
                ->afterStateUpdated(function (Set $set, Get $get): void {
                    static::recalculateTotal($set, $get);
                }),
            TextInput::make('tax')
                ->numeric()
                ->default('0.00')
                ->minValue(0)
                ->formatStateUsing(fn ($state) => $state !== null ? number_format((int) $state / 100, 2, '.', '') : '0.00')
                ->dehydrateStateUsing(fn ($state) => $state !== null ? (int) round((float) $state * 100) : 0)
                ->live()
                ->afterStateUpdated(function (Set $set, Get $get): void {
                    static::recalculateTotal($set, $get);
                }),
            TextInput::make('discount')
                ->numeric()
                ->default('0.00')
                ->minValue(0)
                ->formatStateUsing(fn ($state) => $state !== null ? number_format((int) $state / 100, 2, '.', '') : '0.00')
                ->dehydrateStateUsing(fn ($state) => $state !== null ? (int) round((float) $state * 100) : 0)
                ->live()
                ->afterStateUpdated(function (Set $set, Get $get): void {
                    static::recalculateTotal($set, $get);
                }),
            TextInput::make('total')
                ->numeric()
                ->default('0.00')
                ->minValue(0)
                ->formatStateUsing(fn ($state) => $state !== null ? number_format((int) $state / 100, 2, '.', '') : '0.00')
                ->dehydrateStateUsing(fn ($state) => $state !== null ? (int) round((float) $state * 100) : 0),
            TextInput::make('_shipping_cost')
                ->label('Shipping cost')
                ->numeric()
                ->default('0.00')
                ->minValue(0)
                ->formatStateUsing(fn ($state) => $state !== null ? number_format((int) $state / 100, 2, '.', '') : '0.00')
                ->dehydrateStateUsing(fn ($state) => $state !== null ? (int) round((float) $state * 100) : 0),
            Textarea::make('notes')
                ->columnSpanFull(),
        ];
    }

    /** @return array<Component> */
    private static function orderCurrencyFields(): array
    {
        $options = static::currencyOptions();

        if (count($options) === 1) {
            $value = array_key_first($options);

            return [
                Hidden::make('currency')->default($value),
                TextInput::make('currency_display')
                    ->label('Currency')
                    ->default($value)
                    ->formatStateUsing(fn ($record) => $record?->currency->value ?? $value)
                    ->disabled()
                    ->dehydrated(false),
            ];
        }

        return [
            Select::make('currency')
                ->options($options)
                ->required()
                ->formatStateUsing(fn ($state) => $state instanceof \BackedEnum ? $state->value : $state)
                ->afterStateHydrated(function (Select $component, $state): void {
                    if ($state instanceof \BackedEnum) {
                        $component->state($state->value);
                    }
                }),
        ];
    }

    /**
     * Recalculate order totals from line items.
     */
    protected static function recalculateOrderTotals(Set $set, Get $get): void
    {
        $lines = $get('lines') ?? [];
        $subtotal = 0.0;

        foreach ($lines as $line) {
            $subtotal += (float) ($line['line_total'] ?? 0);
        }

        $set('subtotal', number_format($subtotal, 2, '.', ''));

        $tax = (float) ($get('tax') ?? 0);
        $discount = (float) ($get('discount') ?? 0);
        $set('total', number_format($subtotal + $tax - $discount, 2, '.', ''));
    }

    /**
     * Recalculate the total from subtotal, tax, and discount.
     */
    protected static function recalculateTotal(Set $set, Get $get): void
    {
        $subtotal = (float) ($get('subtotal') ?? 0);
        $tax = (float) ($get('tax') ?? 0);
        $discount = (float) ($get('discount') ?? 0);

        $set('total', number_format($subtotal + $tax - $discount, 2, '.', ''));
    }

    protected static function updateStatusAction(): Actions\Action
    {
        return Actions\Action::make('updateStatus')
            ->label('Update Status')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->hidden(fn (Order $record): bool => $record->status->allowedTransitions() === [])
            ->form([
                Select::make('status')
                    ->label('New Status')
                    ->options(fn (Order $record): array => collect($record->status->allowedTransitions())
                        ->mapWithKeys(fn (OrderStatus $status) => [$status->value => $status->label()])
                        ->all())
                    ->required(),
            ])
            ->action(function (Order $record, array $data): void {
                $newStatus = OrderStatus::from($data['status']);

                app(UpdateOrderStatus::class)($record, $newStatus);

                Notification::make()
                    ->title("Order status updated to {$newStatus->label()}")
                    ->success()
                    ->send();
            });
    }
}
