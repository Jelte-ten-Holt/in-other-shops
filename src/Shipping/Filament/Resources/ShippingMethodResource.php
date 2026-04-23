<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Filament\Resources;

use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Shipping\Filament\Resources\ShippingMethodResource\Pages;
use InOtherShops\Shipping\Models\ShippingMethod;

final class ShippingMethodResource extends Resource
{
    protected static ?string $model = ShippingMethod::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static string|\UnitEnum|null $navigationGroup = 'Shop';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Shipping Method')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g. Standard shipping'),
                        TextInput::make('identifier')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->helperText('Stable slug stored on orders for historic reference. Do not change after orders exist.')
                            ->placeholder('standard'),
                        TextInput::make('base_cost')
                            ->label('Base cost (cents)')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->helperText('595 = €5.95.'),
                        Select::make('currency')
                            ->options(collect(Currency::cases())->mapWithKeys(fn (Currency $c) => [$c->value => $c->value])->all())
                            ->required()
                            ->default(Currency::EUR->value),
                        TextInput::make('sort_order')
                            ->numeric()
                            ->default(0)
                            ->minValue(0),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('identifier')->searchable(),
                Tables\Columns\TextColumn::make('base_cost')
                    ->label('Base cost')
                    ->formatStateUsing(fn (int $state): string => number_format($state / 100, 2))
                    ->sortable(),
                Tables\Columns\TextColumn::make('currency'),
                Tables\Columns\TextColumn::make('sort_order')->sortable(),
                Tables\Columns\IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
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
            'index' => Pages\ListShippingMethods::route('/'),
            'create' => Pages\CreateShippingMethod::route('/create'),
            'edit' => Pages\EditShippingMethod::route('/{record}/edit'),
        ];
    }
}
