<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Filament\Resources;

use Filament\Actions;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use InOtherShops\Support\Filament\NavigationGroup;
use InOtherShops\Support\Filament\PackageResource;
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
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Location\Filament\LocationSchema;
use InOtherShops\Payment\Filament\RelationManagers\PaymentsRelationManager;
use InOtherShops\Shipping\Filament\RelationManagers\ShipmentsRelationManager;
use InOtherShops\Support\Filament\BackedEnumState;
use InOtherShops\Support\Filament\MoneyFields;

class OrderResource extends PackageResource
{
    protected static ?string $model = Order::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Commerce;

    protected static function labelKey(): string
    {
        return 'shops-commerce::orders';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Tabs::make('order')
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make(__('shops-commerce::orders.tabs.details'))
                            ->schema(static::orderDetailFields()),
                        Tab::make(__('shops-commerce::orders.tabs.order_lines'))
                            ->schema([
                                CommerceSchema::orderLinesRepeater(
                                    currencyOptions: static::currencyOptions(),
                                )
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, Get $get): void {
                                        static::recalculateOrderTotals($set, $get);
                                    }),
                            ]),
                        Tab::make(__('shops-commerce::orders.tabs.addresses'))
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
                    ->label(__('shops-commerce::orders.fields.order_number'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('customer.name')
                    ->label(__('shops-commerce::orders.fields.customer'))
                    ->placeholder(__('shops-commerce::orders.placeholders.guest'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('shops-common::fields.status'))
                    ->badge()
                    ->color(fn (OrderStatus $state): string => $state->color()),
                Tables\Columns\TextColumn::make('refund_state')
                    ->label(__('shops-commerce::orders.columns.refund'))
                    ->badge()
                    ->color('danger')
                    ->getStateUsing(function (Order $record): ?string {
                        // One sum query per row instead of the two the
                        // isRefunded()/isPartiallyRefunded() pair would fire.
                        $refunded = $record->refundedTotal();

                        return match (true) {
                            $refunded > 0 && $refunded >= $record->total => __('shops-commerce::orders.refund_state.refunded'),
                            $refunded > 0 => __('shops-commerce::orders.refund_state.partial'),
                            default => null,
                        };
                    }),
                Tables\Columns\TextColumn::make('currency')
                    ->label(__('shops-common::fields.currency'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state->value),
                Tables\Columns\TextColumn::make('shipping_cost')
                    ->label(__('shops-commerce::orders.columns.shipping'))
                    ->formatStateUsing(fn ($record) => $record->shipping_cost > 0
                        ? $record->currency->format($record->shipping_cost)
                        : '—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('total')
                    ->label(__('shops-commerce::orders.fields.total'))
                    ->formatStateUsing(fn ($record) => $record->currency->format($record->total))
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('shops-common::fields.created_at'))
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
            ShipmentsRelationManager::class,
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
                ->label(__('shops-commerce::orders.fields.customer'))
                ->relationship('customer', 'name')
                ->searchable()
                ->preload()
                ->placeholder(__('shops-commerce::orders.placeholders.guest_no_customer')),
            TextInput::make('email')
                ->label(__('shops-common::fields.email'))
                ->email()
                ->maxLength(255),
            TextInput::make('order_number')
                ->label(__('shops-commerce::orders.fields.order_number'))
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                ->disabled(fn (?Order $record): bool => $record !== null),
            Select::make('status')
                ->label(__('shops-common::fields.status'))
                ->options(OrderStatus::class)
                ->default(OrderStatus::Pending)
                ->required()
                ->disabled(fn (?Order $record): bool => $record !== null),
            ...static::orderCurrencyFields(),
            MoneyFields::moneyInput('subtotal', zeroWhenEmpty: true)
                ->label(__('shops-commerce::orders.fields.subtotal'))
                ->live()
                ->afterStateUpdated(function (Set $set, Get $get): void {
                    static::recalculateTotal($set, $get);
                }),
            MoneyFields::moneyInput('tax', zeroWhenEmpty: true)
                ->label(__('shops-commerce::orders.fields.tax'))
                ->live()
                ->afterStateUpdated(function (Set $set, Get $get): void {
                    static::recalculateTotal($set, $get);
                }),
            MoneyFields::moneyInput('discount', zeroWhenEmpty: true)
                ->label(__('shops-commerce::orders.fields.discount'))
                ->live()
                ->afterStateUpdated(function (Set $set, Get $get): void {
                    static::recalculateTotal($set, $get);
                }),
            // Total is computed (subtotal + tax + shipping_cost - discount) by
            // recalculateTotal() above. Read-only so an admin can't introduce
            // a discrepancy between total and its parts. See H6 in the
            // 2026-05-09 audit / docs/launch-blockers.md.
            MoneyFields::moneyInput('total', zeroWhenEmpty: true)
                ->label(__('shops-commerce::orders.fields.total'))
                ->disabled()
                ->dehydrated(),
            MoneyFields::moneyInput('_shipping_cost', zeroWhenEmpty: true)
                ->label(__('shops-commerce::orders.fields.shipping_cost')),
            Textarea::make('notes')
                ->label(__('shops-common::fields.notes'))
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
                    ->label(__('shops-common::fields.currency'))
                    ->default($value)
                    ->formatStateUsing(fn ($record) => $record?->currency->value ?? $value)
                    ->disabled()
                    ->dehydrated(false),
            ];
        }

        return [
            BackedEnumState::normalize(Select::make('currency'))
                ->label(__('shops-common::fields.currency'))
                ->options($options)
                ->required(),
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
            ->label(__('shops-commerce::orders.actions.update_status'))
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->hidden(fn (Order $record): bool => $record->status->allowedTransitions() === [])
            ->form([
                Select::make('status')
                    ->label(__('shops-commerce::orders.fields.new_status'))
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
