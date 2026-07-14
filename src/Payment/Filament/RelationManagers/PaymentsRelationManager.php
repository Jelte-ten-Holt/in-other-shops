<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Filament\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Payment\PaymentGatewayManager;
use InvalidArgumentException;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('shops-payment::payment.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('gateway_reference')
                    ->label(__('shops-common::fields.reference'))
                    ->searchable()
                    ->url(fn (Payment $record): ?string => $this->resolvePaymentUrl($record), shouldOpenInNewTab: true),
                Tables\Columns\TextColumn::make('gateway')
                    ->label(__('shops-payment::payment.columns.gateway'))
                    ->badge(),
                Tables\Columns\TextColumn::make('amount')
                    ->label(__('shops-payment::payment.columns.amount'))
                    ->formatStateUsing(fn ($record) => $record->currency->format($record->amount)),
                Tables\Columns\TextColumn::make('amount_refunded')
                    ->label(__('shops-payment::payment.columns.refunded'))
                    ->formatStateUsing(fn ($record) => $record->amount_refunded > 0
                        ? $record->currency->format($record->amount_refunded)
                        : '—'),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('shops-common::fields.status'))
                    ->badge()
                    ->color(fn (PaymentStatus $state): string => $state->color()),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('shops-payment::payment.columns.date'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    private function resolvePaymentUrl(Payment $payment): ?string
    {
        try {
            $gateway = app(PaymentGatewayManager::class)->gateway($payment->gateway);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $gateway->paymentDashboardUrl($payment);
    }
}
