<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Filament\RelationManagers;

use Filament\Actions;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Actions\CreatePrice;
use InOtherShops\Pricing\Actions\DeletePrice;
use InOtherShops\Pricing\Actions\UpdatePrice;
use InOtherShops\Pricing\DTOs\PriceData;
use InOtherShops\Pricing\Filament\PricingSchema;

class PricesRelationManager extends RelationManager
{
    protected static string $relationship = 'prices';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                PricingSchema::currencySelect(),
                PricingSchema::amountField(),
                PricingSchema::compareAtAmountField(),
                PricingSchema::compareAtUntilField(),
                PricingSchema::minimumQuantityField(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('currency')
                    ->label(__('shops-common::fields.currency'))
                    ->badge(),
                Tables\Columns\TextColumn::make('amount')
                    ->label(__('shops-pricing::price.amount'))
                    ->formatStateUsing(fn ($record) => $record->formattedAmount())
                    ->sortable(),
                Tables\Columns\TextColumn::make('compare_at_amount')
                    ->label(__('shops-pricing::price.strikethrough'))
                    ->formatStateUsing(fn ($record) => $record->compare_at_amount
                        ? $record->currency->format($record->compare_at_amount)
                        : '—'
                    ),
                Tables\Columns\TextColumn::make('compare_at_until')
                    ->label(__('shops-pricing::price.compare_at_until'))
                    ->dateTime()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('minimum_quantity')
                    ->label(__('shops-pricing::price.minimum_quantity'))
                    ->sortable(),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->using(fn (array $data) => (new CreatePrice)(
                        priceable: $this->getOwnerRecord(),
                        data: self::priceData($data),
                    )),
            ])
            ->actions([
                Actions\EditAction::make()
                    ->using(fn ($record, array $data) => (new UpdatePrice)(
                        price: $record,
                        data: self::priceData($data),
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

    /**
     * @param  array<string, mixed>  $data
     */
    private static function priceData(array $data): PriceData
    {
        return new PriceData(
            amount: (int) $data['amount'],
            currency: Currency::from($data['currency']),
            compareAtAmount: isset($data['compare_at_amount']) ? (int) $data['compare_at_amount'] : null,
            compareAtUntil: isset($data['compare_at_until']) ? Carbon::parse($data['compare_at_until']) : null,
            priceListId: isset($data['price_list_id']) ? (int) $data['price_list_id'] : null,
            minimumQuantity: (int) ($data['minimum_quantity'] ?? 1),
        );
    }
}
