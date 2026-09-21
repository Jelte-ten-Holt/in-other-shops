<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Actions;

use InOtherShops\Commerce\Order\Enums\ConfirmOrderOutcome;
use InOtherShops\Commerce\Order\Events\OrderConfirmationBlocked;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Inventory\Actions\ConfirmReservation;
use InOtherShops\Support\Concerns\RunsLockedTransactions;

/**
 * Idempotently confirm a paid order, exactly once, with the stock guard that F14
 * was missing. Locks the order so it serialises against order-expiry (P3) and
 * any racing confirmation:
 *
 *  - already Confirmed → {@see ConfirmOrderOutcome::AlreadyConfirmed} (the
 *    redelivery / double-event case — the caller must NOT re-send the buyer's
 *    confirmation email or re-clear the cart; that was the real F2/F3 bug);
 *  - not Pending (e.g. Cancelled by order-expiry) → flagged + `NotConfirmable`;
 *  - Pending but any of its reservations was already released (F14 — the cron
 *    pulled the stock back while payment was in flight, for every line or only
 *    some) → flagged + `StockUnavailable`, NOT silently confirmed against stock
 *    that is no longer held. The rule is {@see Order::hasReleasedStock()};
 *    {@see UpdateOrderStatus} enforces it on every confirm, this checks first so
 *    the payment path gets an outcome and an event instead of an exception;
 *  - Pending with stock still held → confirm reservations, transition to
 *    Confirmed, `Confirmed`.
 *
 * The flagged cases dispatch {@see OrderConfirmationBlocked} so a paid-but-
 * unfulfillable order is audited and an operator can restock+confirm or refund.
 */
final class ConfirmOrder
{
    use RunsLockedTransactions;

    public function __construct(
        private readonly ConfirmReservation $confirmReservation,
        private readonly UpdateOrderStatus $updateOrderStatus,
    ) {}

    public function __invoke(Order $order): ConfirmOrderOutcome
    {
        return $this->withLocked($order, function (?Order $locked) use ($order): ConfirmOrderOutcome {
            if ($locked === null) {
                return ConfirmOrderOutcome::NotConfirmable;
            }

            if ($locked->status === OrderStatus::Confirmed) {
                $order->setRawAttributes($locked->getAttributes(), true);

                return ConfirmOrderOutcome::AlreadyConfirmed;
            }

            if ($locked->status !== OrderStatus::Pending) {
                OrderConfirmationBlocked::dispatch($locked, "payment succeeded but order is {$locked->status->value}");

                return ConfirmOrderOutcome::NotConfirmable;
            }

            if ($locked->hasReleasedStock()) {
                OrderConfirmationBlocked::dispatch($locked, 'stock reservations were released before payment confirmed');

                return ConfirmOrderOutcome::StockUnavailable;
            }

            ($this->confirmReservation)($locked, 'Order confirmed on payment success');
            ($this->updateOrderStatus)($locked, OrderStatus::Confirmed);
            $order->setRawAttributes($locked->getAttributes(), true);

            return ConfirmOrderOutcome::Confirmed;
        });
    }
}
