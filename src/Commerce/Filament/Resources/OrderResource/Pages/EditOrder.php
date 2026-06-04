<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Filament\Resources\OrderResource\Pages;

use DomainException;
use Filament\Actions;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use InOtherShops\Commerce\Filament\Resources\OrderResource;
use InOtherShops\Commerce\Order\Actions\RefundOrder;
use InOtherShops\Commerce\Order\DTOs\RefundActor;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Inventory\Enums\ReservationStatus;
use InOtherShops\Inventory\Inventory;
use InOtherShops\Payment\Enums\PaymentStatus;

final class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            static::partialRefundAction(),
            static::refundAndCancelAction(),
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Soft guard for the residual F28 risk: a refunded-but-not-cancelled order
     * stays Confirmed (and thus fulfillable). Warn the operator whenever they
     * open it — including before they create a shipment via the relation manager
     * on this page. Warns, does not block (per warn-don't-force).
     */
    protected function afterFill(): void
    {
        /** @var Order $order */
        $order = $this->record;

        if ($order->isRefunded()) {
            Notification::make()
                ->title('This order is fully refunded')
                ->body('It is still Confirmed — do not fulfil or ship it without checking.')
                ->warning()
                ->persistent()
                ->send();
        } elseif ($order->isPartiallyRefunded()) {
            Notification::make()
                ->title('This order is partially refunded')
                ->warning()
                ->send();
        }
    }

    /**
     * Partial (or full-without-cancel) refund. Amount + reason, and a picker of
     * the order's still-held reservations to restock (whole-line). The order
     * stays a fulfilment concept — refunded-ness is read from order->refunds.
     */
    public static function partialRefundAction(): Actions\Action
    {
        return Actions\Action::make('partialRefund')
            ->label('Partial refund')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->visible(fn (Order $record): bool => static::isRefundable($record))
            ->form(fn (Order $record): array => [
                TextInput::make('amount')
                    ->label('Amount to refund (minor units)')
                    ->helperText('Leave blank for the full remaining balance.')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(max(1, $record->total - $record->refundedTotal())),
                Textarea::make('reason')
                    ->label('Reason')
                    ->rows(2)
                    ->maxLength(500),
                CheckboxList::make('restock')
                    ->label('Restock these items (optional)')
                    ->options(static::reservationOptions($record))
                    ->helperText('Releases the chosen reservations back to available stock.'),
            ])
            ->action(function (Order $record, array $data): void {
                static::runRefund(function () use ($record, $data): void {
                    app(RefundOrder::class)(
                        order: $record,
                        actor: static::adminActor(),
                        amount: static::intOrNull($data['amount'] ?? null),
                        reason: $data['reason'] ?? null,
                        restockReservationIds: array_map('intval', $data['restock'] ?? []),
                    );
                });
            });
    }

    /**
     * Full refund AND cancel the order — the existing cancel path blanket-
     * releases every reservation, so the per-line picker is intentionally absent.
     */
    public static function refundAndCancelAction(): Actions\Action
    {
        return Actions\Action::make('refundAndCancel')
            ->label('Refund & cancel order')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Order $record): bool => static::isRefundable($record)
                && $record->status !== OrderStatus::Cancelled)
            ->form(fn (): array => [
                Textarea::make('reason')
                    ->label('Reason')
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->requiresConfirmation()
            ->modalDescription('Refunds the full remaining balance, cancels the order, and releases all '
                .'its reserved stock.')
            ->action(function (Order $record, array $data): void {
                static::runRefund(function () use ($record, $data): void {
                    app(RefundOrder::class)(
                        order: $record,
                        actor: static::adminActor(),
                        reason: $data['reason'] ?? null,
                        cancelOrder: true,
                    );
                });
            });
    }

    private static function isRefundable(Order $record): bool
    {
        return $record->refundedTotal() < $record->total
            && $record->payments()
                ->whereIn('status', [PaymentStatus::Succeeded, PaymentStatus::PartiallyRefunded])
                ->exists();
    }

    /**
     * @return array<int, string>
     */
    private static function reservationOptions(Order $record): array
    {
        $model = Inventory::stockReservation();

        return $model::query()
            ->where('reference_type', $record->getMorphClass())
            ->where('reference_id', $record->getKey())
            ->whereIn('status', [ReservationStatus::Pending, ReservationStatus::Confirmed])
            ->get()
            ->mapWithKeys(fn ($reservation): array => [
                $reservation->id => "Reservation #{$reservation->id} — qty {$reservation->quantity}",
            ])
            ->all();
    }

    private static function adminActor(): RefundActor
    {
        $user = auth()->user();
        $label = is_object($user) && isset($user->name) && is_string($user->name) ? $user->name : null;

        return RefundActor::admin((string) (auth()->id() ?? 'unknown'), $label);
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value !== null && $value !== '' ? (int) $value : null;
    }

    private static function runRefund(callable $refund): void
    {
        try {
            $refund();
        } catch (DomainException $e) {
            Notification::make()->title('Refund refused')->body($e->getMessage())->danger()->send();

            return;
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            Notification::make()->title('Refund failed')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Refund issued')->success()->send();
    }
}
