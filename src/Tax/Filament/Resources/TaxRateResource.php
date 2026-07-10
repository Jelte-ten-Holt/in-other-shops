<?php

declare(strict_types=1);

namespace InOtherShops\Tax\Filament\Resources;

use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use InOtherShops\Support\Filament\NavigationGroup;
use InOtherShops\Support\Filament\PackageResource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use InOtherShops\Tax\Enums\TaxCategory;
use InOtherShops\Support\Filament\MoneyFields;
use InOtherShops\Tax\Filament\Resources\TaxRateResource\Pages;
use InOtherShops\Tax\Models\TaxRate;

final class TaxRateResource extends PackageResource
{
    protected static ?string $model = TaxRate::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Tax;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('shops-tax::taxrate.section.tax_rate'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('shops-common::fields.name'))
                            ->required()
                            ->maxLength(255)
                            ->placeholder(__('shops-tax::taxrate.fields.name_placeholder')),
                        TextInput::make('country_code')
                            ->label(__('shops-tax::taxrate.fields.country_code'))
                            ->required()
                            ->minLength(2)
                            ->maxLength(2)
                            ->helperText(__('shops-tax::taxrate.fields.country_code_help')),
                        Select::make('tax_category')
                            ->label(__('shops-tax::taxrate.fields.tax_category'))
                            ->options(collect(TaxCategory::cases())->mapWithKeys(fn (TaxCategory $c) => [$c->value => $c->value])->all())
                            ->placeholder(__('shops-tax::taxrate.fields.tax_category_placeholder'))
                            ->helperText(__('shops-tax::taxrate.fields.tax_category_help')),
                        TextInput::make('rate_bps')
                            ->label(__('shops-tax::taxrate.fields.rate_bps'))
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->maxValue(10000)
                            ->helperText(__('shops-tax::taxrate.fields.rate_bps_help')),
                        Toggle::make('is_default')
                            ->label(__('shops-tax::taxrate.fields.is_default'))
                            ->helperText(__('shops-tax::taxrate.fields.is_default_help')),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label(__('shops-common::fields.name'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('country_code')->label(__('shops-common::fields.country'))->sortable(),
                Tables\Columns\TextColumn::make('tax_category')
                    ->label(__('shops-tax::taxrate.columns.category'))
                    ->placeholder(__('shops-tax::taxrate.columns.category_placeholder'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('rate_bps')
                    ->label(__('shops-tax::taxrate.columns.rate'))
                    // D2 (package-tightening): was '21.00%'; now matches the
                    // voucher column's stripped format ('21%', '7.5%').
                    ->formatStateUsing(fn (int $state): string => MoneyFields::percentLabel($state))
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_default')->label(__('shops-tax::taxrate.columns.is_default'))->boolean(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('shops-common::fields.updated_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('country_code')
            ->actions([
                Actions\EditAction::make(),
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
            'index' => Pages\ListTaxRates::route('/'),
            'create' => Pages\CreateTaxRate::route('/create'),
            'edit' => Pages\EditTaxRate::route('/{record}/edit'),
        ];
    }
}
