<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Filament\Resources;

use Filament\Actions;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
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
use InOtherShops\Commerce\Exceptions\InvalidOrderStatusTransitionException;
use InOtherShops\Commerce\Filament\CommerceSchema;
use InOtherShops\Commerce\Filament\OrderStockPreview;
use InOtherShops\Commerce\Filament\Resources\OrderResource\Pages;
use InOtherShops\Commerce\Order\Actions\UpdateOrderStatus;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Inventory\Enums\ReservationStatus;
use InOtherShops\Inventory\Inventory;
use InOtherShops\Inventory\Models\StockHold;
use InOtherShops\Inventory\Models\StockReservation;
use InOtherShops\Location\Filament\LocationSchema;
use InOtherShops\Payment\Filament\RelationManagers\PaymentsRelationManager;
use InOtherShops\Shipping\Filament\RelationManagers\ShipmentsRelationManager;
use InOtherShops\Support\Filament\BackedEnumState;
use InOtherShops\Support\Filament\MoneyFields;
use InOtherShops\Support\ModelLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

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
                                ...static::stockChoiceFields(),
                                CommerceSchema::orderLinesRepeater(
                                    currencyOptions: static::currencyOptions(),
                                    extraLineFields: [static::lineStockSplit()],
                                )
                                    // Locked once paid, and while the order holds
                                    // stock (stock-holds brief D6/D13); the model
                                    // refuses either way. Filament skips a disabled
                                    // relationship repeater on save, so the rest of
                                    // the order still saves.
                                    ->disabled(fn (?Order $record): bool => static::isFrozen($record) || static::holdsStock($record))
                                    ->helperText(fn (?Order $record): ?string => ! static::isFrozen($record) && static::holdsStock($record)
                                        ? __('shops-commerce::orders.stock.lines_locked')
                                        : null)
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, Get $get): void {
                                        static::recalculateOrderTotals($set, $get);
                                        static::syncStockChoice($set, $get);
                                    }),
                                static::heldStockSummary(),
                            ]),
                        Tab::make(__('shops-commerce::orders.tabs.addresses'))
                            ->schema([
                                LocationSchema::addressRepeater()
                                    ->disabled(fn (?Order $record): bool => static::isFrozen($record)),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // One aggregate for the whole page instead of a SUM per row on the
            // most-visited admin list (S1 of the 2026-09-07 audit). A consumer
            // that overrides this callback loses the aggregate, not the column:
            // the refund cell falls back to the per-row query.
            ->modifyQueryUsing(fn (Builder $query): Builder => static::withOldestOpenReservation($query->withSum('refunds', 'amount')))
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
                        // Prefer the table's page-wide aggregate; degrade to the
                        // per-row sum (never to "unrefunded") if a consumer has
                        // replaced modifyQueryUsing.
                        //
                        // Keyed on the attribute's PRESENCE, not on null: withSum
                        // returns NULL for an order with no refunds, so a `??`
                        // here would fall through to the per-row query on every
                        // unrefunded row — which is nearly every row.
                        $refunded = array_key_exists('refunds_sum_amount', $record->getAttributes())
                            ? (int) $record->refunds_sum_amount
                            : $record->refundedTotal();

                        return match (true) {
                            $refunded > 0 && $refunded >= $record->total => __('shops-commerce::orders.refund_state.refunded'),
                            $refunded > 0 => __('shops-commerce::orders.refund_state.partial'),
                            default => null,
                        };
                    }),
                Tables\Columns\TextColumn::make('stock_state')
                    ->label(__('shops-commerce::orders.columns.stock'))
                    ->badge()
                    ->color('warning')
                    ->getStateUsing(fn (Order $record): ?string => static::staleStockLabel($record)),
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
            ]);
        // No bulk delete (D4/M3): a bulk action can't tell a fresh, payment-less
        // Pending order from a paid one, and deleting a paid order destroys the
        // record of money that moved. Per-order deletion of a genuinely-deletable
        // order stays available on the edit page, gated by Order::isDeletable().
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
     * A paid order's money fields, lines and addresses are shown but cannot be
     * edited; the model refuses the change anyway (Order::isFrozen).
     */
    protected static function isFrozen(?Order $record): bool
    {
        return $record !== null && $record->isFrozen();
    }

    protected static function holdsStock(?Order $record): bool
    {
        return $record !== null && $record->exists && $record->holdsStock();
    }

    /**
     * How a new order takes its stock (stock-holds brief D2): from main stock
     * (the default, D11), from one hold the admin picks (D4), or not at all —
     * for recording an order whose stock was already corrected by hand. Shown
     * on create only; the choice is not stored (an order without reservations
     * chose "don't take stock", or predates this).
     *
     * `_stock_held` is the snapshot of what the picked hold holds, taken when
     * it is picked or the lines change. Each line's split is shown from it,
     * and the save refuses a hold that changed in between (§4.2).
     *
     * @return array<Component>
     */
    protected static function stockChoiceFields(): array
    {
        return [
            Radio::make('_stock_mode')
                ->label(__('shops-commerce::orders.stock.mode'))
                ->options(fn (Get $get): array => static::stockModeOptions($get))
                ->descriptions([
                    OrderStockPreview::NO_STOCK => __('shops-commerce::orders.stock.modes.none_help'),
                ])
                ->default(OrderStockPreview::FROM_STOCK)
                ->required()
                ->live()
                ->dehydrated(false)
                ->hidden(fn (?Order $record): bool => $record !== null)
                ->afterStateUpdated(fn (Set $set, Get $get) => static::syncStockChoice($set, $get)),
            Select::make('_stock_hold_id')
                ->label(__('shops-commerce::orders.stock.hold'))
                ->options(fn (Get $get): array => OrderStockPreview::qualifyingHolds($get('lines') ?? []))
                ->required(fn (Get $get): bool => $get('_stock_mode') === OrderStockPreview::FROM_HOLD)
                ->visible(fn (Get $get, ?Order $record): bool => $record === null && $get('_stock_mode') === OrderStockPreview::FROM_HOLD)
                ->live()
                ->dehydrated(false)
                ->afterStateUpdated(fn (Set $set, Get $get) => static::snapshotHold($set, $get)),
            Hidden::make('_stock_held')
                ->default([])
                ->dehydrated(false),
        ];
    }

    /**
     * "Take from a hold" is offered only while an open hold holds one of the
     * lines' products (D4).
     *
     * @return array<string, string>
     */
    protected static function stockModeOptions(Get $get): array
    {
        $options = [OrderStockPreview::FROM_STOCK => __('shops-commerce::orders.stock.modes.stock')];

        if (OrderStockPreview::qualifyingHolds($get('lines') ?? []) !== []) {
            $options[OrderStockPreview::FROM_HOLD] = __('shops-commerce::orders.stock.modes.hold');
        }

        $options[OrderStockPreview::NO_STOCK] = __('shops-commerce::orders.stock.modes.none');

        return $options;
    }

    /**
     * Keeps the hold choice honest as the lines change: no qualifying hold
     * falls back to main stock; exactly one is preselected, visibly (D4); the
     * snapshot follows.
     */
    protected static function syncStockChoice(Set $set, Get $get): void
    {
        if ($get('_stock_mode') !== OrderStockPreview::FROM_HOLD) {
            return;
        }

        $holds = OrderStockPreview::qualifyingHolds($get('lines') ?? []);

        if ($holds === []) {
            $set('_stock_mode', OrderStockPreview::FROM_STOCK);
            $set('_stock_hold_id', null);
            $set('_stock_held', []);

            return;
        }

        if (blank($get('_stock_hold_id')) && count($holds) === 1) {
            $set('_stock_hold_id', array_key_first($holds));
        }

        static::snapshotHold($set, $get);
    }

    protected static function snapshotHold(Set $set, Get $get): void
    {
        $hold = blank($get('_stock_hold_id')) ? null : StockHold::query()->find($get('_stock_hold_id'));

        $set('_stock_held', $hold?->heldByGroup() ?? []);
    }

    /**
     * Each line says where its stock will come from, before saving (D5).
     */
    protected static function lineStockSplit(): Placeholder
    {
        return Placeholder::make('_stock_split')
            ->label(__('shops-commerce::orders.stock.split.label'))
            ->content(fn (Get $get, Component $component): ?string => static::stockAnalysis($get, '../../')['splits'][static::lineKey($component)] ?? null)
            ->visible(fn (Get $get, Component $component): bool => filled(static::stockAnalysis($get, '../../')['splits'][static::lineKey($component)] ?? null))
            ->columnSpanFull();
    }

    /**
     * @return array{splits: array<string, string>, errors: array<string, string>}
     */
    public static function stockAnalysis(Get $get, string $prefix = ''): array
    {
        $mode = $get($prefix.'_stock_mode');

        if (blank($mode)) {
            return ['splits' => [], 'errors' => []];
        }

        $hold = blank($get($prefix.'_stock_hold_id')) ? null : StockHold::query()->find($get($prefix.'_stock_hold_id'));

        return OrderStockPreview::analyse(
            lines: $get($prefix.'lines') ?? [],
            mode: $mode,
            held: $mode === OrderStockPreview::FROM_HOLD ? ($get($prefix.'_stock_held') ?? []) : [],
            holdName: $hold === null ? null : OrderStockPreview::holdLabel($hold),
        );
    }

    private static function lineKey(Component $component): string
    {
        return Str::afterLast((string) $component->getContainer()->getStatePath(), '.');
    }

    /**
     * What an existing order holds, read-only: "1 × Bowl (from Markt 15 Nov)".
     */
    protected static function heldStockSummary(): Placeholder
    {
        return Placeholder::make('_held_stock')
            ->label(__('shops-commerce::orders.stock.held_summary'))
            ->content(fn (?Order $record): HtmlString => static::heldStockLines($record))
            ->visible(fn (?Order $record): bool => static::holdsStock($record));
    }

    private static function heldStockLines(?Order $record): HtmlString
    {
        $reservations = Inventory::stockReservation()::query()
            ->where('reference_type', $record?->getMorphClass())
            ->where('reference_id', $record?->getKey())
            ->whereIn('status', [ReservationStatus::Pending, ReservationStatus::Confirmed])
            ->with(['stockItem.stockable', 'stockHold'])
            ->orderBy('id')
            ->get();

        return new HtmlString($reservations
            ->map(fn (StockReservation $reservation): string => e(__('shops-commerce::orders.stock.held_line', [
                'count' => $reservation->quantity,
                'item' => $reservation->stockItem?->stockable === null ? '?' : ModelLabel::for($reservation->stockItem->stockable),
                'source' => $reservation->stockHold === null
                    ? __('shops-commerce::orders.stock.from_stock')
                    : __('shops-commerce::orders.stock.from_hold', ['hold' => $reservation->stockHold->name]),
            ])))
            ->implode('<br>'));
    }

    /**
     * The orders list's stale badge (D15): one subquery for the page — the
     * oldest open-ended Pending reservation of each order — never a query per
     * row (2026-09-07 audit S1).
     */
    protected static function withOldestOpenReservation(Builder $query): Builder
    {
        $order = $query->getModel();

        return $query->addSelect(['oldest_open_reservation_at' => Inventory::stockReservation()::query()
            ->select('created_at')
            ->whereColumn('reference_id', $order->qualifyColumn($order->getKeyName()))
            ->where('reference_type', $order->getMorphClass())
            ->where('status', ReservationStatus::Pending)
            ->whereNull('reserved_until')
            ->orderBy('created_at')
            ->limit(1)]);
    }

    /**
     * "Holding stock 9 days" on a Pending order that has held stock past
     * `inventory.hold_warn_after_days` — a bank transfer that never came shows
     * up where orders are looked at. Degrades to a per-row query (never to
     * "not stale") when a consumer has replaced modifyQueryUsing.
     */
    protected static function staleStockLabel(Order $record): ?string
    {
        if ($record->status !== OrderStatus::Pending) {
            return null;
        }

        $oldest = array_key_exists('oldest_open_reservation_at', $record->getAttributes())
            ? $record->getAttributes()['oldest_open_reservation_at']
            : static::withOldestOpenReservation(Order::query()->whereKey($record->getKey()))->value('oldest_open_reservation_at');

        if ($oldest === null) {
            return null;
        }

        $since = Carbon::parse($oldest);

        if ($since->copy()->addDays(Inventory::holdWarnAfterDays())->gte(now())) {
            return null;
        }

        return __('shops-commerce::orders.stock_state.stale', ['days' => (int) $since->diffInDays(now())]);
    }

    /**
     * The currencies an order may be in: the enabled ones (`currency.enabled`,
     * else every case). One enabled currency fixes the field; more give a
     * select. Override to narrow the list. The default was `[]` until v0.72.0,
     * which made the required select reject every value — a consumer that
     * mounted this page unchanged could not save an order at all.
     *
     * @return array<string, string>
     */
    protected static function currencyOptions(): array
    {
        return Currency::enabledOptions();
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
                ->disabled(fn (?Order $record): bool => static::isFrozen($record))
                ->live()
                ->afterStateUpdated(function (Set $set, Get $get): void {
                    static::recalculateTotal($set, $get);
                }),
            MoneyFields::moneyInput('tax', zeroWhenEmpty: true)
                ->label(__('shops-commerce::orders.fields.tax'))
                ->disabled(fn (?Order $record): bool => static::isFrozen($record))
                ->live()
                ->afterStateUpdated(function (Set $set, Get $get): void {
                    static::recalculateTotal($set, $get);
                }),
            MoneyFields::moneyInput('discount', zeroWhenEmpty: true)
                ->label(__('shops-commerce::orders.fields.discount'))
                ->disabled(fn (?Order $record): bool => static::isFrozen($record))
                ->live()
                ->afterStateUpdated(function (Set $set, Get $get): void {
                    static::recalculateTotal($set, $get);
                }),
            // The voucher behind the discount, read-only: it is a snapshot of
            // what was redeemed, and an admin retyping it would make the order
            // claim a campaign it was never priced under. Hidden when the order
            // carried no voucher, so manual orders don't show an empty field.
            TextInput::make('voucher_code')
                ->label(__('shops-commerce::orders.fields.voucher_code'))
                ->disabled()
                ->dehydrated(false)
                ->visible(fn (Get $get): bool => filled($get('voucher_code'))),
            // Total is computed (subtotal − discount + shipping_cost — the
            // PriceBreakdown identity; under the inclusive tax mode `tax` is
            // already contained in `subtotal` and must NOT be added on top) by
            // recalculateTotal() above. Read-only so an admin can't introduce
            // a discrepancy between total and its parts. See H6 in the
            // 2026-05-09 audit / docs/launch-blockers.md.
            MoneyFields::moneyInput('total', zeroWhenEmpty: true)
                ->label(__('shops-commerce::orders.fields.total'))
                ->disabled()
                ->dehydrated(),
            MoneyFields::moneyInput('shipping_cost', zeroWhenEmpty: true)
                ->label(__('shops-commerce::orders.fields.shipping_cost'))
                ->disabled(fn (?Order $record): bool => static::isFrozen($record))
                ->live()
                ->afterStateUpdated(function (Set $set, Get $get): void {
                    static::recalculateTotal($set, $get);
                }),
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
                ->disabled(fn (?Order $record): bool => static::isFrozen($record))
                ->required(),
        ];
    }

    /**
     * Recalculate order totals from line items, then the total via the
     * domain identity (see recalculateTotal).
     */
    protected static function recalculateOrderTotals(Set $set, Get $get): void
    {
        $lines = $get('lines') ?? [];
        $subtotal = 0.0;

        foreach ($lines as $line) {
            $subtotal += (float) ($line['line_total'] ?? 0);
        }

        $set('subtotal', number_format($subtotal, 2, '.', ''));

        static::recalculateTotal($set, $get);
    }

    /**
     * Recalculate the total from its parts via the PriceBreakdown identity:
     * `total = subtotal − discount + shipping_cost`. Under the inclusive tax
     * mode `tax` is the VAT already CONTAINED in `subtotal` — adding it here
     * (the pre-v0.60 formula `subtotal + tax − discount`) double-counted VAT
     * and dropped shipping, so the recomputed admin total disagreed with
     * every total CreateOrder persists.
     */
    protected static function recalculateTotal(Set $set, Get $get): void
    {
        $set('total', static::computeTotal(
            subtotal: (float) ($get('subtotal') ?? 0),
            discount: (float) ($get('discount') ?? 0),
            shippingCost: (float) ($get('shipping_cost') ?? 0),
        ));
    }

    /**
     * The identity itself, pure and directly testable (the suite has no
     * Livewire panel harness to drive the form end-to-end). Inputs are the
     * form's major-unit decimals, not cents.
     */
    public static function computeTotal(float $subtotal, float $discount, float $shippingCost): string
    {
        return number_format($subtotal - $discount + $shippingCost, 2, '.', '');
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

                // Refusals are expected here, not faults: confirming an order
                // whose stock was released (F14), or a page left open while
                // the order moved on. Say why instead of a 500.
                try {
                    app(UpdateOrderStatus::class)($record, $newStatus);
                } catch (InvalidOrderStatusTransitionException $e) {
                    Notification::make()
                        ->title(__('shops-commerce::orders.notifications.status_refused'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('shops-commerce::orders.notifications.status_updated', ['status' => $newStatus->label()]))
                    ->success()
                    ->send();
            });
    }
}
