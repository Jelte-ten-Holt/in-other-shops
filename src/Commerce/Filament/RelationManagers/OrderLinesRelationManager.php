<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Filament\RelationManagers;

use InOtherShops\Commerce\Filament\CommerceSchema;
use InOtherShops\Commerce\Order\Models\Order;
use Filament\Actions;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class OrderLinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('shops-commerce::orders.lines_title');
    }

    /**
     * Override in your project to provide currency options.
     *
     * @return array<string, string>
     */
    protected function currencyOptions(): array
    {
        return [];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema(
                CommerceSchema::orderLineFields(
                    currencyOptions: $this->currencyOptions(),
                )
            );
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('description')
                    ->label(__('shops-common::fields.description'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('sku')
                    ->label(__('shops-common::fields.sku')),
                Tables\Columns\TextColumn::make('quantity')
                    ->label(__('shops-common::fields.quantity'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('unit_price')
                    ->label(__('shops-commerce::orders.fields.unit_price'))
                    ->formatStateUsing(fn ($record) => $record->currency->format($record->unit_price))
                    ->sortable(),
                Tables\Columns\TextColumn::make('line_total')
                    ->label(__('shops-commerce::orders.fields.line_total'))
                    ->formatStateUsing(fn ($record) => $record->currency->format($record->line_total))
                    ->sortable(),
            ])
            ->headerActions([
                Actions\CreateAction::make(),
            ])
            ->actions([
                Actions\EditAction::make(),
                // D4/M3 class: the lines of an order you can't delete are the
                // durable record of what was sold — they must survive the same
                // way the order and its addresses do. Gated on the owner order.
                Actions\DeleteAction::make()
                    ->hidden(fn (): bool => ! $this->ownerOrderIsDeletable()),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make()
                        ->hidden(fn (): bool => ! $this->ownerOrderIsDeletable()),
                ]),
            ]);
    }

    private function ownerOrderIsDeletable(): bool
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof Order && $owner->isDeletable();
    }
}
