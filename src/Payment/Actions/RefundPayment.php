<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Actions;

use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Events\PaymentRefunded;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Payment\PaymentGatewayManager;
use InvalidArgumentException;

final class RefundPayment
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
    ) {}

    public function __invoke(Payment $payment, ?int $amount = null): Payment
    {
        $this->validateRefundable($payment);

        $refundAmount = $this->resolveRefundAmount($payment, $amount);

        $this->processRefund($payment, $refundAmount);

        $this->updatePaymentRecord($payment, $refundAmount);

        $this->dispatchEvent($payment);

        return $payment;
    }

    private function validateRefundable(Payment $payment): void
    {
        $refundable = [
            PaymentStatus::Succeeded,
            PaymentStatus::PartiallyRefunded,
        ];

        if (! in_array($payment->status, $refundable, true)) {
            throw new InvalidArgumentException(
                "Payment [{$payment->id}] cannot be refunded in status [{$payment->status->value}].",
            );
        }
    }

    private function resolveRefundAmount(Payment $payment, ?int $amount): int
    {
        $maxRefundable = $payment->amount - $payment->amount_refunded;

        if ($amount === null) {
            return $maxRefundable;
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException('Refund amount must be positive.');
        }

        if ($amount > $maxRefundable) {
            throw new InvalidArgumentException(
                "Refund amount [{$amount}] exceeds refundable amount [{$maxRefundable}].",
            );
        }

        return $amount;
    }

    private function processRefund(Payment $payment, int $amount): void
    {
        $gateway = $this->gateways->gateway($payment->gateway);
        $gateway->refund($payment, $amount);
    }

    private function updatePaymentRecord(Payment $payment, int $refundAmount): void
    {
        $newAmountRefunded = $payment->amount_refunded + $refundAmount;

        $status = $newAmountRefunded >= $payment->amount
            ? PaymentStatus::Refunded
            : PaymentStatus::PartiallyRefunded;

        $payment->update([
            'amount_refunded' => $newAmountRefunded,
            'status' => $status,
        ]);
    }

    private function dispatchEvent(Payment $payment): void
    {
        PaymentRefunded::dispatch($payment);
    }
}
