<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Filament\RelationManagers;

use InOtherShops\Location\Enums\AddressType;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class OrderAddressesRelationManager extends RelationManager
{
    protected static string $relationship = 'addresses';

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('shops-commerce::orders.addresses_title');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('type')
                    ->label(__('shops-common::fields.type'))
                    ->options(AddressType::class)
                    ->required(),
                TextInput::make('first_name')
                    ->label(__('shops-commerce::orders.address.first_name'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('last_name')
                    ->label(__('shops-commerce::orders.address.last_name'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('line_1')
                    ->label(__('shops-commerce::orders.address.line_1'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('line_2')
                    ->label(__('shops-commerce::orders.address.line_2'))
                    ->maxLength(255),
                TextInput::make('city')
                    ->label(__('shops-commerce::orders.address.city'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('state')
                    ->label(__('shops-commerce::orders.address.state'))
                    ->maxLength(255),
                TextInput::make('postal_code')
                    ->label(__('shops-commerce::orders.address.postal_code'))
                    ->required()
                    ->maxLength(20),
                TextInput::make('country_code')
                    ->label(__('shops-common::fields.country_code'))
                    ->required()
                    ->maxLength(2)
                    ->minLength(2),
                TextInput::make('phone')
                    ->label(__('shops-commerce::orders.address.phone'))
                    ->tel()
                    ->maxLength(50),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('type')
                    ->label(__('shops-common::fields.type'))
                    ->badge(),
                Tables\Columns\TextColumn::make('first_name')
                    ->label(__('shops-commerce::orders.address.first_name')),
                Tables\Columns\TextColumn::make('last_name')
                    ->label(__('shops-commerce::orders.address.last_name')),
                Tables\Columns\TextColumn::make('line_1')
                    ->label(__('shops-commerce::orders.address.address')),
                Tables\Columns\TextColumn::make('city')
                    ->label(__('shops-commerce::orders.address.city')),
                Tables\Columns\TextColumn::make('postal_code')
                    ->label(__('shops-commerce::orders.address.postal_code')),
                Tables\Columns\TextColumn::make('country_code')
                    ->label(__('shops-common::fields.country')),
            ])
            ->headerActions([
                Actions\CreateAction::make(),
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
}
