<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Filament\RelationManagers;

use DomainException;
use Filament\Actions;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use InOtherShops\Payment\Actions\RefundPayment;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Payment\PaymentGatewayManager;
use InvalidArgumentException;

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
            ->defaultSort('created_at', 'desc')
            ->actions([
                static::refundAction(),
            ]);
    }

    /**
     * Wired to `RefundPayment` so the admin doesn't need to reconcile via
     * the gateway dashboard. Status is recomputed by the action (Succeeded
     * → PartiallyRefunded or Refunded based on totals); the row redraws on
     * success because Filament reads the fresh model after the action
     * closure returns.
     *
     * Errors surface as Filament notifications rather than 500s: domain
     * exceptions (refund-cap-exceeded, not-refundable, invalid-amount) and
     * gateway-side runtime exceptions both render with the underlying
     * message so the operator knows whether to retry, fix the input, or
     * escalate.
     */
    private static function refundAction(): Actions\Action
    {
        return Actions\Action::make('refund')
            ->label('Refund')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->visible(fn (Payment $record): bool => in_array(
                $record->status,
                [PaymentStatus::Succeeded, PaymentStatus::PartiallyRefunded],
                true,
            ) && $record->amount_refunded < $record->amount)
            ->form(fn (Payment $record): array => [
                TextInput::make('amount')
                    ->label('Amount to refund (minor units)')
                    ->helperText(fn () => 'Maximum refundable: '
                        .$record->currency->format($record->amount - $record->amount_refunded)
                        .'. Leave blank for full refund of the remaining balance.')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue($record->amount - $record->amount_refunded),
                Textarea::make('reason')
                    ->label('Reason (admin-only note)')
                    ->helperText('Optional. Not sent to the gateway today; surfaces in the audit log.')
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->requiresConfirmation()
            ->modalDescription(fn (Payment $record) => 'Refund issued through '.$record->gateway
                .'. The payment row updates and a PaymentRefunded event fires so listeners (audit log, customer email, etc.) run.')
            ->action(function (Payment $record, array $data): void {
                $amount = isset($data['amount']) && $data['amount'] !== ''
                    ? (int) $data['amount']
                    : null;

                try {
                    app(RefundPayment::class)($record, $amount);
                } catch (DomainException $e) {
                    Notification::make()
                        ->title('Refund refused')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                } catch (InvalidArgumentException $e) {
                    Notification::make()
                        ->title('Refund input invalid')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                } catch (\RuntimeException $e) {
                    Notification::make()
                        ->title('Gateway refund failed')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Refund issued')
                    ->success()
                    ->send();
            });
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
