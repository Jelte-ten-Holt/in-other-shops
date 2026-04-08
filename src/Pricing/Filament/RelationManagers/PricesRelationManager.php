<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Filament\RelationManagers;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Actions\CreatePrice;
use InOtherShops\Pricing\Actions\DeletePrice;
use InOtherShops\Pricing\Actions\UpdatePrice;
use InOtherShops\Pricing\Filament\PricingSchema;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class PricesRelationManager extends RelationManager
{
    protected static string $relationship = 'prices';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                PricingSchema::currencySelect(),
                TextInput::make('amount')
                    ->required()
                    ->numeric()
                    ->minValue(0),
                TextInput::make('compare_at_amount')
                    ->numeric()
                    ->minValue(0),
                Select::make('price_list_id')
                    ->relationship('priceList', 'name')
                    ->searchable()
                    ->preload(),
                TextInput::make('minimum_quantity')
                    ->numeric()
                    ->default(1)
                    ->minValue(1),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('currency')
                    ->badge(),
                Tables\Columns\TextColumn::make('amount')
                    ->formatStateUsing(fn ($record) => $record->formattedAmount())
                    ->sortable(),
                Tables\Columns\TextColumn::make('compare_at_amount')
                    ->formatStateUsing(fn ($record) => $record->compare_at_amount
                        ? $record->currency->format($record->compare_at_amount)
                        : '—'
                    ),
                Tables\Columns\TextColumn::make('priceList.name')
                    ->placeholder('Default'),
                Tables\Columns\TextColumn::make('minimum_quantity')
                    ->sortable(),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->using(fn (array $data) => (new CreatePrice)(
                        priceable: $this->getOwnerRecord(),
                        amount: (int) $data['amount'],
                        currency: Currency::from($data['currency']),
                        compareAtAmount: isset($data['compare_at_amount']) ? (int) $data['compare_at_amount'] : null,
                        priceListId: isset($data['price_list_id']) ? (int) $data['price_list_id'] : null,
                        minimumQuantity: (int) ($data['minimum_quantity'] ?? 1),
                    )),
            ])
            ->actions([
                Actions\EditAction::make()
                    ->using(fn ($record, array $data) => (new UpdatePrice)(
                        price: $record,
                        amount: (int) $data['amount'],
                        currency: Currency::from($data['currency']),
                        compareAtAmount: isset($data['compare_at_amount']) ? (int) $data['compare_at_amount'] : null,
                        priceListId: isset($data['price_list_id']) ? (int) $data['price_list_id'] : null,
                        minimumQuantity: (int) ($data['minimum_quantity'] ?? 1),
                    )),
                Actions\DeleteAction::make()
                    ->using(fn ($record) => (new DeletePrice)($record)),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
