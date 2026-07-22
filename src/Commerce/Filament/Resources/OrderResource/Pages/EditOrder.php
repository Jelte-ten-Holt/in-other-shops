<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Filament\Resources\OrderResource\Pages;

use DomainException;
use Filament\Actions;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use InOtherShops\Support\Filament\PackageEditRecord;
use InOtherShops\Commerce\Filament\Resources\OrderResource;
use InOtherShops\Commerce\Order\Actions\RefundOrder;
use InOtherShops\Commerce\Order\DTOs\RefundActor;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Inventory\Enums\ReservationStatus;
use InOtherShops\Inventory\Inventory;
use InOtherShops\Payment\Enums\PaymentStatus;

final class EditOrder extends PackageEditRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            static::partialRefundAction(),
            static::refundAndCancelAction(),
            // D4/M3: only a fresh Pending, payment-less order is deletable —
            // deleting a paid order destroys the record of money that moved.
            Actions\DeleteAction::make()
                ->hidden(fn (Order $record): bool => ! $record->isDeletable()),
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

        // One sum query instead of the two isRefunded()/isPartiallyRefunded()
        // would each fire; same derivation as the model predicates.
        $refunded = $order->refundedTotal();

        if ($refunded > 0 && $refunded >= $order->total) {
            Notification::make()
                ->title(__('shops-commerce::orders.notifications.fully_refunded_title'))
                ->body(__('shops-commerce::orders.notifications.fully_refunded_body'))
                ->warning()
                ->persistent()
                ->send();
        } elseif ($refunded > 0) {
            Notification::make()
                ->title(__('shops-commerce::orders.notifications.partially_refunded_title'))
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
            ->label(__('shops-commerce::orders.actions.partial_refund'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->visible(fn (Order $record): bool => static::isRefundable($record))
            ->form(fn (Order $record): array => [
                TextInput::make('amount')
                    ->label(__('shops-commerce::orders.fields.refund_amount'))
                    ->helperText(__('shops-commerce::orders.fields.refund_amount_help'))
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(max(1, $record->total - $record->refundedTotal())),
                Textarea::make('reason')
                    ->label(__('shops-commerce::orders.fields.reason'))
                    ->rows(2)
                    ->maxLength(500),
                CheckboxList::make('restock')
                    ->label(__('shops-commerce::orders.fields.restock'))
                    ->options(static::reservationOptions($record))
                    ->helperText(__('shops-commerce::orders.fields.restock_help')),
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
            ->label(__('shops-commerce::orders.actions.refund_and_cancel'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Order $record): bool => static::isRefundable($record)
                && $record->status !== OrderStatus::Cancelled)
            ->form(fn (): array => [
                Textarea::make('reason')
                    ->label(__('shops-commerce::orders.fields.reason'))
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->requiresConfirmation()
            ->modalDescription(__('shops-commerce::orders.actions.refund_and_cancel_modal'))
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
            Notification::make()->title(__('shops-commerce::orders.notifications.refund_refused'))->body($e->getMessage())->danger()->send();

            return;
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            Notification::make()->title(__('shops-commerce::orders.notifications.refund_failed'))->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title(__('shops-commerce::orders.notifications.refund_issued'))->success()->send();
    }
}
