<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Actions;

use Carbon\CarbonImmutable;
use InOtherShops\Commerce\Exceptions\CommerceException;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Events\PaymentSucceeded;
use InOtherShops\Payment\Exceptions\PaymentNotCancelableException;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Support\Concerns\RunsLockedTransactions;

/**
 * Records that an order was paid outside any gateway — a bank transfer, cash on
 * collection — on the day the admin says, for its full total (bookkeeping brief
 * §12 P-1). Only through this explicit step, never when an order is created.
 *
 * The payment is a `manual` gateway row with `paid_at` set and no settlement.
 * PaymentSucceeded fires inside the same transaction, exactly as for a card
 * payment, so the consumer's own listener confirms the order and tells the
 * customer, and a listener that fails takes the payment row back with it. Who
 * recorded it is the request's actor on the audit row PaymentSucceeded writes.
 *
 * A failed card attempt does not stand in the way, but its intent is closed
 * first (CancelOpenGatewaySessions), so the customer cannot pay twice.
 */
final class RecordManualPayment
{
    use RunsLockedTransactions;

    public const string GATEWAY = 'manual';

    public function __construct(
        private readonly CancelOpenGatewaySessions $cancelOpenGatewaySessions,
    ) {}

    public function __invoke(Order $order, CarbonImmutable $paidAt): Payment
    {
        if ($paidAt->isFuture()) {
            throw new CommerceException("Order {$order->order_number} cannot be recorded as paid on a day that has not come yet.");
        }

        if (! $order->acceptsManualPayment()) {
            throw $this->refused($order);
        }

        // A declined card attempt leaves its intent live and retryable from the
        // customer's payment page: close it first, outside the lock, or a late
        // retry would charge them a second time for an order already paid.
        try {
            ($this->cancelOpenGatewaySessions)($order);
        } catch (PaymentNotCancelableException $e) {
            throw new CommerceException("Order {$order->order_number} cannot be marked paid: a card payment for it is still going through.", previous: $e);
        }

        return $this->withLocked($order, function (?Order $locked) use ($order, $paidAt): Payment {
            if ($locked === null || ! $locked->acceptsManualPayment()) {
                throw $this->refused($order);
            }

            /** @var Payment $payment */
            $payment = $locked->payments()->create([
                'amount' => $locked->total,
                'amount_refunded' => 0,
                'currency' => $locked->currency,
                'status' => PaymentStatus::Succeeded,
                'gateway' => self::GATEWAY,
                'gateway_reference' => null,
                'paid_at' => $paidAt,
            ]);

            PaymentSucceeded::dispatch($payment);

            return $payment;
        });
    }

    private function refused(Order $order): CommerceException
    {
        return new CommerceException("Order {$order->order_number} cannot be marked paid: it is cancelled, has nothing to pay, or already has a payment.");
    }
}
