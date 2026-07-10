<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Filament\RelationManagers;

use InOtherShops\Commerce\Order\Enums\OrderStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class CustomerOrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('order_number')
                    ->label(__('shops-commerce::orders.fields.order_number'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('shops-common::fields.status'))
                    ->badge()
                    ->color(fn (OrderStatus $state): string => $state->color()),
                Tables\Columns\TextColumn::make('total')
                    ->label(__('shops-commerce::orders.fields.total'))
                    ->formatStateUsing(fn ($record) => $record->currency->format($record->total))
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('shops-common::fields.created_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
