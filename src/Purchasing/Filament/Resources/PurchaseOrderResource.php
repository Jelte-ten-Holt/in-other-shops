<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Filament\Resources;

use InOtherShops\Purchasing\Actions\CancelPurchaseOrder;
use InOtherShops\Purchasing\Actions\PlacePurchaseOrder;
use InOtherShops\Purchasing\Actions\ReceiveItems;
use InOtherShops\Purchasing\Enums\PurchaseOrderStatus;
use InOtherShops\Purchasing\Filament\PurchasingSchema;
use InOtherShops\Purchasing\Filament\Resources\PurchaseOrderResource\Pages;
use InOtherShops\Purchasing\Models\PurchaseOrder;
use InOtherShops\Support\Filament\MoneyFields;
use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use InOtherShops\Support\Filament\NavigationGroup;
use InOtherShops\Support\Filament\PackageResource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class PurchaseOrderResource extends PackageResource
{
    protected static ?string $model = PurchaseOrder::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Purchasing;

    protected static function labelKey(): string
    {
        return 'shops-purchasing::purchaseorder';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('shops-purchasing::purchaseorder.section.details'))
                    ->schema([
                        Select::make('supplier_id')
                            ->label(__('shops-purchasing::purchaseorder.fields.supplier'))
                            ->relationship('supplier', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->disabledOn('edit'),
                        TextInput::make('reference')
                            ->label(__('shops-common::fields.reference'))
                            ->disabled()
                            ->dehydrated(false)
                            ->visibleOn('edit'),
                        DatePicker::make('expected_delivery_at')
                            ->label(__('shops-purchasing::purchaseorder.fields.expected_delivery')),
                        ...self::costField('shipping_cost', __('shops-purchasing::purchaseorder.fields.shipping_cost')),
                        ...self::costField('customs_cost', __('shops-purchasing::purchaseorder.fields.customs_cost')),
                        Textarea::make('notes')
                            ->label(__('shops-common::fields.notes'))
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make(__('shops-purchasing::purchaseorder.section.lines'))
                    ->schema([
                        PurchasingSchema::purchaseLinesRepeater(),
                    ])
                    ->visibleOn('create'),
            ]);
    }

    /**
     * A net-cents money field rendered/edited as a decimal. Disabled on edit —
     * header costs are set at creation (they feed the total computed by the
     * CreatePurchaseOrder action).
     *
     * @return array<\Filament\Forms\Components\TextInput>
     */
    private static function costField(string $name, string $label): array
    {
        return [
            MoneyFields::moneyInput($name, zeroWhenEmpty: true)
                ->label($label)
                ->disabledOn('edit'),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('reference')
                    ->label(__('shops-common::fields.reference'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('supplier.name')
                    ->label(__('shops-purchasing::purchaseorder.fields.supplier'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('shops-common::fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (PurchaseOrderStatus $state): string => $state->label())
                    ->color(fn (PurchaseOrderStatus $state): string => $state->color()),
                Tables\Columns\TextColumn::make('total')
                    ->label(__('shops-purchasing::purchaseorder.columns.total'))
                    ->formatStateUsing(fn (PurchaseOrder $record): string => $record->currency->format($record->total))
                    ->sortable(),
                Tables\Columns\TextColumn::make('expected_delivery_at')
                    ->label(__('shops-purchasing::purchaseorder.columns.expected'))
                    ->date()
                    ->placeholder('—')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('shops-common::fields.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('shops-common::fields.status'))
                    ->options(fn (): array => collect(PurchaseOrderStatus::cases())
                        ->mapWithKeys(fn (PurchaseOrderStatus $status) => [$status->value => $status->label()])
                        ->all()),
            ])
            ->actions([
                self::placeAction(),
                self::receiveAction(),
                self::cancelAction(),
                Actions\EditAction::make(),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    private static function placeAction(): Actions\Action
    {
        return Actions\Action::make('place')
            ->label(__('shops-purchasing::purchaseorder.actions.place'))
            ->icon('heroicon-o-paper-airplane')
            ->color('info')
            ->requiresConfirmation()
            ->visible(fn (PurchaseOrder $record): bool => $record->status === PurchaseOrderStatus::Draft)
            ->action(function (PurchaseOrder $record): void {
                app(PlacePurchaseOrder::class)($record);

                Notification::make()->title(__('shops-purchasing::purchaseorder.notifications.placed'))->success()->send();
            });
    }

    private static function receiveAction(): Actions\Action
    {
        return Actions\Action::make('receive')
            ->label(__('shops-purchasing::purchaseorder.actions.receive'))
            ->icon('heroicon-o-inbox-stack')
            ->color('success')
            ->visible(fn (PurchaseOrder $record): bool => $record->status->isReceivable())
            ->form(fn (PurchaseOrder $record): array => self::receiveFields($record))
            ->action(function (PurchaseOrder $record, array $data): void {
                $quantities = [];

                foreach (($data['receive'] ?? []) as $lineId => $quantity) {
                    $quantity = (int) $quantity;
                    if ($quantity > 0) {
                        $quantities[(int) $lineId] = $quantity;
                    }
                }

                if ($quantities === []) {
                    Notification::make()->title(__('shops-purchasing::purchaseorder.notifications.nothing_to_receive'))->warning()->send();

                    return;
                }

                try {
                    app(ReceiveItems::class)($record, $quantities);
                    Notification::make()->title(__('shops-purchasing::purchaseorder.notifications.items_received'))->success()->send();
                } catch (\DomainException $e) {
                    Notification::make()->title(__('shops-purchasing::purchaseorder.notifications.receive_failed'))->body($e->getMessage())->danger()->send();
                }
            });
    }

    /**
     * @return array<\Filament\Forms\Components\TextInput>
     */
    private static function receiveFields(PurchaseOrder $record): array
    {
        $fields = [];

        foreach ($record->lines()->get() as $line) {
            $outstanding = $line->outstandingQuantity();

            if ($outstanding <= 0) {
                continue;
            }

            $fields[] = TextInput::make("receive.{$line->id}")
                ->label("{$line->description}  ·  outstanding {$outstanding}")
                ->numeric()
                ->integer()
                ->minValue(0)
                ->maxValue($outstanding)
                ->default(0);
        }

        return $fields;
    }

    private static function cancelAction(): Actions\Action
    {
        return Actions\Action::make('cancel')
            ->label(__('shops-purchasing::purchaseorder.actions.cancel'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (PurchaseOrder $record): bool => $record->status->canTransitionTo(PurchaseOrderStatus::Cancelled))
            ->action(function (PurchaseOrder $record): void {
                try {
                    app(CancelPurchaseOrder::class)($record);
                    Notification::make()->title(__('shops-purchasing::purchaseorder.notifications.cancelled'))->success()->send();
                } catch (\DomainException $e) {
                    Notification::make()->title(__('shops-purchasing::purchaseorder.notifications.cancel_failed'))->body($e->getMessage())->danger()->send();
                }
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchaseOrders::route('/'),
            'create' => Pages\CreatePurchaseOrder::route('/create'),
            'edit' => Pages\EditPurchaseOrder::route('/{record}/edit'),
        ];
    }
}
