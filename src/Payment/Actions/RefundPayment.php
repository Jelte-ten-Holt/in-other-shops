<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Actions;

use Illuminate\Support\Facades\DB;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Events\PaymentRefunded;
use InOtherShops\Payment\Exceptions\PaymentNotRefundableException;
use InOtherShops\Payment\Exceptions\RefundAmountExceededException;
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
        // Two simultaneous refund clicks on the same payment used to read
        // amount_refunded=0 in parallel, both pass the cap check, both
        // call the gateway. Locking the row + re-validating the cap under
        // lock closes that race. Gateway call comes before the DB write
        // because gateways enforce their own cap from their own records
        // (Stripe inspects the PaymentIntent it issued); calling them
        // post-update would feed them a payment that already reflects the
        // refund and they'd reject it as exceeding the remaining cap.
        return DB::transaction(function () use ($payment, $amount): Payment {
            $locked = Payment::query()
                ->lockForUpdate()
                ->findOrFail($payment->getKey());

            $this->validateRefundable($locked);
            $refundAmount = $this->resolveRefundAmount($locked, $amount);

            $this->processRefund($locked, $refundAmount);
            $this->updatePaymentRecord($locked, $refundAmount);
            $this->dispatchEvent($locked);

            // Refresh the caller's instance so they see the new state too.
            $payment->setRawAttributes($locked->getAttributes(), sync: true);

            return $payment;
        });
    }

    private function validateRefundable(Payment $payment): void
    {
        $refundable = [
            PaymentStatus::Succeeded,
            PaymentStatus::PartiallyRefunded,
        ];

        if (! in_array($payment->status, $refundable, true)) {
            throw PaymentNotRefundableException::inStatus($payment, $payment->status);
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
            throw RefundAmountExceededException::exceeds($amount, $maxRefundable);
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
