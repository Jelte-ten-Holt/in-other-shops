<?php

declare(strict_types=1);

namespace InOtherShops\Tax\Filament\Resources;

use Filament\Actions;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use InOtherShops\Tax\Filament\Resources\TaxRateResource\Pages;
use InOtherShops\Tax\Models\TaxRate;

final class TaxRateResource extends Resource
{
    protected static ?string $model = TaxRate::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static string|\UnitEnum|null $navigationGroup = 'Shop';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Tax Rate')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g. Netherlands VAT 21%'),
                        TextInput::make('country_code')
                            ->label('Country code (ISO-3166-1 alpha-2)')
                            ->required()
                            ->minLength(2)
                            ->maxLength(2)
                            ->helperText('Two-letter uppercase code, e.g. NL, DE, FR.'),
                        TextInput::make('rate_bps')
                            ->label('Rate (basis points)')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->maxValue(10000)
                            ->helperText('2100 = 21%. 900 = 9%. 0 = zero-rated.'),
                        Toggle::make('is_default')
                            ->label('Default fallback')
                            ->helperText('Used when no country match is found. Only one rate should be marked as default.'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('country_code')->label('Country')->sortable(),
                Tables\Columns\TextColumn::make('rate_bps')
                    ->label('Rate')
                    ->formatStateUsing(fn (int $state): string => number_format($state / 100, 2).'%')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_default')->label('Default')->boolean(),
                Tables\Columns\TextColumn::make('updated_at')
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

    public static function getRelations(): array
    {
        return [];
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
