<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Filament\RelationManagers;

use InOtherShops\Commerce\Filament\CommerceSchema;
use Filament\Actions;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class OrderLinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    /**
     * Override in your project to register orderable models.
     *
     * @return array<string, class-string<\InOtherShops\Commerce\Order\Contracts\Orderable>>
     */
    protected function orderableModels(): array
    {
        return [];
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
                    orderableModels: $this->orderableModels(),
                    currencyOptions: $this->currencyOptions(),
                )
            );
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('description')
                    ->searchable(),
                Tables\Columns\TextColumn::make('sku')
                    ->label('SKU'),
                Tables\Columns\TextColumn::make('quantity')
                    ->sortable(),
                Tables\Columns\TextColumn::make('unit_price')
                    ->formatStateUsing(fn ($record) => $record->currency->format($record->unit_price))
                    ->sortable(),
                Tables\Columns\TextColumn::make('line_total')
                    ->formatStateUsing(fn ($record) => $record->currency->format($record->line_total))
                    ->sortable(),
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
