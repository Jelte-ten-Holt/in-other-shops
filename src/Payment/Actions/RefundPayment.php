<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Actions;

use Illuminate\Support\Facades\DB;
use InOtherShops\Payment\DTOs\RefundResult;
use InOtherShops\Payment\Enums\PaymentStatus;
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

    /**
     * Issue the gateway refund and update the payment row. Returns the gateway
     * refund id + amounts so the orchestration layer (Commerce RefundOrder, or
     * the webhook reconciliation listener) can record the Refund row and reverse
     * tax. Deliberately does NOT record the Refund row or dispatch a domain
     * event itself — recording is Commerce's job (Payment must not depend on it),
     * and keeping the Refund row + restock OUT of this gateway transaction means
     * a post-refund failure can't roll back a successful gateway charge into a
     * record-less state.
     */
    public function __invoke(Payment $payment, ?int $amount = null): RefundResult
    {
        // Two simultaneous refund clicks on the same payment used to read
        // amount_refunded=0 in parallel, both pass the cap check, both
        // call the gateway. Locking the row + re-validating the cap under
        // lock closes that race. Gateway call comes before the DB write
        // because gateways enforce their own cap from their own records
        // (Stripe inspects the PaymentIntent it issued); calling them
        // post-update would feed them a payment that already reflects the
        // refund and they'd reject it as exceeding the remaining cap.
        //
        // ACCEPTED RESIDUAL (F34): the gateway call and the amount_refunded
        // write share this transaction, so a gateway-success-then-DB-failure
        // (or commit failure) rolls the local row back while the money has
        // actually moved. We accept this rather than redesign into a reserve-
        // then-compensate flow, because two backstops bound the blast radius:
        //  - the `charge.refunded` webhook reconciles amount_refunded
        //    monotonically (ProcessPaymentWebhook::applyRefund, `max(...)`), so
        //    the row becomes consistent without operator action; and
        //  - the gateway's own independent cap rejects a re-clicked refund of
        //    money it already returned, so the stale local row cannot cause a
        //    double refund.
        // Both are pinned by tests (RefundPaymentTest +
        // ProcessPaymentWebhookRefundTest). Revisit if async payment methods
        // land (the webhook may then arrive much later — same trigger as the
        // deferred F14 auto-refund).
        return DB::transaction(function () use ($payment, $amount): RefundResult {
            $locked = Payment::query()
                ->lockForUpdate()
                ->findOrFail($payment->getKey());

            $this->validateRefundable($locked);
            $refundAmount = $this->resolveRefundAmount($locked, $amount);

            $gatewayRefundId = $this->processRefund($locked, $refundAmount);
            $this->updatePaymentRecord($locked, $refundAmount);

            // Refresh the caller's instance so they see the new state too.
            $payment->setRawAttributes($locked->getAttributes(), sync: true);

            return new RefundResult(
                gatewayRefundId: $gatewayRefundId,
                amount: $refundAmount,
                cumulativeRefunded: $locked->amount_refunded,
            );
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

    private function processRefund(Payment $payment, int $amount): string
    {
        $gateway = $this->gateways->gateway($payment->gateway);

        return $gateway->refund($payment, $amount);
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
}
