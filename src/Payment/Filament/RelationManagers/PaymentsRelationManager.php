<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Filament\RelationManagers;

use InOtherShops\Payment\Contracts\PaymentGateway;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Models\Payment;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('gateway_reference')
                    ->label('Reference')
                    ->searchable()
                    ->url(fn (Payment $record): ?string => $this->resolvePaymentUrl($record), shouldOpenInNewTab: true),
                Tables\Columns\TextColumn::make('gateway')
                    ->badge(),
                Tables\Columns\TextColumn::make('amount')
                    ->formatStateUsing(fn ($record) => $record->currency->format($record->amount)),
                Tables\Columns\TextColumn::make('amount_refunded')
                    ->label('Refunded')
                    ->formatStateUsing(fn ($record) => $record->amount_refunded > 0
                        ? $record->currency->format($record->amount_refunded)
                        : '—'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (PaymentStatus $state): string => $state->color()),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    private function resolvePaymentUrl(Payment $payment): ?string
    {
        return app(PaymentGateway::class)->paymentDashboardUrl($payment);
    }
}
