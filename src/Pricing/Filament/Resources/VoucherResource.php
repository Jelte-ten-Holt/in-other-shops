<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Filament\Resources;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Enums\VoucherType;
use InOtherShops\Pricing\Filament\PricingSchema;
use InOtherShops\Pricing\Filament\Resources\VoucherResource\Pages;
use InOtherShops\Pricing\Models\Voucher;
use Filament\Actions;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

final class VoucherResource extends Resource
{
    protected static ?string $model = Voucher::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-ticket';

    protected static string|\UnitEnum|null $navigationGroup = 'Pricing';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Voucher Details')
                    ->schema([
                        TextInput::make('code')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255)
                            ->helperText(fn (string $operation) => $operation === 'create' ? 'A random 4-letter suffix will be appended (e.g. SUMMER → SUMMER-KXPQ).' : null),
                        Select::make('type')
                            ->options([
                                VoucherType::Fixed->value => 'Fixed amount',
                                VoucherType::Percentage->value => 'Percentage',
                            ])
                            ->required()
                            ->live(),
                        TextInput::make('amount')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->suffix(fn (Get $get) => $get('type') === VoucherType::Percentage->value ? '%' : null),
                        PricingSchema::currencySelect()
                            ->hidden(fn (Get $get) => $get('type') === VoucherType::Percentage->value)
                            ->required(fn (Get $get) => $get('type') === VoucherType::Fixed->value),
                    ])
                    ->columns(2),

                Section::make('Restrictions')
                    ->schema([
                        TextInput::make('minimum_order_amount')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->helperText('Minimum order subtotal in cents (0 = no minimum)'),
                        TextInput::make('max_uses')
                            ->numeric()
                            ->minValue(1)
                            ->placeholder('Unlimited'),
                        DateTimePicker::make('valid_from')
                            ->placeholder('No start date'),
                        DateTimePicker::make('valid_until')
                            ->placeholder('No expiry'),
                    ])
                    ->columns(2),

                Section::make('Status')
                    ->schema([
                        Toggle::make('is_active')
                            ->default(true),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->badge(),
                Tables\Columns\TextColumn::make('amount')
                    ->formatStateUsing(fn (Voucher $record) => $record->type === VoucherType::Percentage
                        ? "{$record->amount}%"
                        : ($record->currency instanceof Currency
                            ? $record->currency->format($record->amount)
                            : $record->amount)
                    ),
                Tables\Columns\TextColumn::make('times_used')
                    ->label('Uses')
                    ->formatStateUsing(fn (Voucher $record) => $record->max_uses !== null
                        ? "{$record->times_used} / {$record->max_uses}"
                        : (string) $record->times_used
                    ),
                Tables\Columns\TextColumn::make('valid_until')
                    ->dateTime()
                    ->placeholder('No expiry')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Active'),
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
