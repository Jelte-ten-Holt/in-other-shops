<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Filament\Resources;

use Filament\Actions;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use InOtherShops\Support\Filament\NavigationGroup;
use InOtherShops\Support\Filament\PackageResource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Enums\VoucherType;
use InOtherShops\Pricing\Filament\PricingSchema;
use InOtherShops\Pricing\Filament\Resources\VoucherResource\Pages;
use InOtherShops\Pricing\Models\Voucher;
use InOtherShops\Support\Filament\MoneyFields;

final class VoucherResource extends PackageResource
{
    protected static ?string $model = Voucher::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-ticket';

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Pricing;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('shops-pricing::voucher.section.details'))
                    ->schema([
                        TextInput::make('code')
                            ->label(__('shops-common::fields.code'))
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255)
                            ->helperText(fn (string $operation) => $operation === 'create' ? __('shops-pricing::voucher.code_help') : null),
                        Select::make('type')
                            ->label(__('shops-common::fields.type'))
                            ->options([
                                VoucherType::Fixed->value => __('shops-pricing::voucher.type_options.fixed'),
                                VoucherType::Percentage->value => __('shops-pricing::voucher.type_options.percentage'),
                            ])
                            ->required()
                            ->live(),
                        TextInput::make('amount')
                            ->label(__('shops-pricing::voucher.amount'))
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->suffix(fn (Get $get) => $get('type') === VoucherType::Percentage->value ? '%' : null)
                            ->helperText(fn (Get $get) => $get('type') === VoucherType::Percentage->value
                                ? __('shops-pricing::voucher.amount_help_percentage')
                                : __('shops-pricing::voucher.amount_help_fixed'))
                            ->formatStateUsing(fn (?int $state, Get $get) => $state !== null && $get('type') === VoucherType::Percentage->value
                                ? $state / 100
                                : $state)
                            ->dehydrateStateUsing(fn (mixed $state, Get $get) => $get('type') === VoucherType::Percentage->value
                                ? MoneyFields::dehydrateBps($state)
                                : (int) $state),
                        PricingSchema::currencySelect()
                            ->hidden(fn (Get $get) => $get('type') === VoucherType::Percentage->value)
                            ->required(fn (Get $get) => $get('type') === VoucherType::Fixed->value),
                    ])
                    ->columns(2),

                Section::make(__('shops-pricing::voucher.section.restrictions'))
                    ->schema([
                        TextInput::make('minimum_order_amount')
                            ->label(__('shops-pricing::voucher.minimum_order_amount'))
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->helperText(__('shops-pricing::voucher.minimum_order_amount_help')),
                        TextInput::make('max_uses')
                            ->label(__('shops-pricing::voucher.max_uses'))
                            ->numeric()
                            ->minValue(1)
                            ->placeholder(__('shops-pricing::voucher.max_uses_placeholder')),
                        DateTimePicker::make('valid_from')
                            ->label(__('shops-pricing::voucher.valid_from'))
                            ->placeholder(__('shops-pricing::voucher.valid_from_placeholder')),
                        DateTimePicker::make('valid_until')
                            ->label(__('shops-pricing::voucher.valid_until'))
                            ->placeholder(__('shops-pricing::voucher.valid_until_placeholder')),
                        Toggle::make('is_active')
                            ->label(__('shops-common::fields.active'))
                            ->default(true)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label(__('shops-common::fields.code'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label(__('shops-common::fields.type'))
                    ->badge(),
                Tables\Columns\TextColumn::make('amount')
                    ->label(__('shops-pricing::voucher.amount'))
                    ->formatStateUsing(fn (Voucher $record) => $record->type === VoucherType::Percentage
                        ? MoneyFields::percentLabel($record->amount)
                        : ($record->currency instanceof Currency
                            ? $record->currency->format($record->amount)
                            : $record->amount)
                    ),
                Tables\Columns\TextColumn::make('times_used')
                    ->label(__('shops-pricing::voucher.uses'))
                    ->formatStateUsing(fn (Voucher $record) => $record->max_uses !== null
                        ? "{$record->times_used} / {$record->max_uses}"
                        : (string) $record->times_used
                    ),
                Tables\Columns\TextColumn::make('valid_until')
                    ->label(__('shops-pricing::voucher.valid_until'))
                    ->dateTime()
                    ->placeholder(__('shops-pricing::voucher.valid_until_placeholder'))
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('shops-common::fields.active'))
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label(__('shops-common::fields.active')),
            ])
            ->actions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVouchers::route('/'),
            'create' => Pages\CreateVoucher::route('/create'),
            'edit' => Pages\EditVoucher::route('/{record}/edit'),
        ];
    }
}
