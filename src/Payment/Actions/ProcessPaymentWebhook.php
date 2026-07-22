<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InOtherShops\Payment\DTOs\WebhookPayload;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Events\PaymentFailed;
use InOtherShops\Payment\Events\PaymentRefunded;
use InOtherShops\Payment\Events\PaymentSucceeded;
use InOtherShops\Payment\Exceptions\PaymentAmountMismatchException;
use InOtherShops\Payment\Exceptions\UnmatchedWebhookPaymentException;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Payment\Models\WebhookEvent;
use InOtherShops\Payment\PaymentGatewayManager;
use InOtherShops\Logging\DTOs\LogActor;
use InOtherShops\Logging\LogContext;

final class ProcessPaymentWebhook
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly LogContext $logContext,
    ) {}

    public function __invoke(string $gatewayName, Request $request): ?Payment
    {
        $gateway = $this->gateways->gateway($gatewayName);

        $gateway->verifyWebhookSignature($request);

        // This request's boundary actor: a gateway acting on its own, with no
        // operator. Every audit row produced downstream (PaymentSucceeded →
        // order confirmation → stock release, refunds) inherits it ambiently
        // unless it sets its own explicit actor (brief, §3).
        $this->logContext->setActor(LogActor::gateway($gatewayName));

        $payload = $gateway->parseWebhook($request);

        return DB::transaction(function () use ($gatewayName, $payload): ?Payment {
            // Resolve the payment BEFORE recording idempotency. An event whose
            // gateway_reference matches no payment yet (delivered before the pay
            // page wrote the reference, or the process died between the gateway
            // call and the write) must leave NO ledger row.
            //
            // For a SETTLED event (succeeded/failed) a miss THROWS
            // (UnmatchedWebhookPaymentException, M10/D8): the resulting non-2xx
            // is what makes the gateway retry — answering 2xx would have it treat
            // the delivery as handled and never resend, losing a real payment
            // event (order stuck Pending, customer charged). The throw rolls back
            // this transaction, so no idempotency row is written and the retry
            // lands once the reference exists. A non-settled miss (e.g. a bare
            // pending/processing ping) is dropped as before — nothing to recover.
            //
            // The payment row lock also serialises concurrent deliveries of the
            // same event, so the ledger insert below stays race-free despite
            // running second.
            $payment = $this->findPayment($gatewayName, $payload);

            if ($payment === null) {
                if ($this->isActionableMiss($payload)) {
                    throw UnmatchedWebhookPaymentException::forReference($gatewayName, $payload->gatewayReference);
                }

                return null;
            }

            if (! $this->recordIdempotency($gatewayName, $payload)) {
                return null;
            }

            $this->guardAmountMatches($payment, $payload);

            if ($this->isRefundEvent($payload)) {
                $this->applyRefund($payment, $payload);
            } elseif ($this->updatePaymentStatus($payment, $payload)) {
                $this->dispatchEvent($payment);
            }

            return $payment;
        });
    }

    /**
     * Insert an idempotency row. Returns false when the delivery was already
     * processed (unique-constraint hit), true on first delivery or when the
     * gateway doesn't supply an event id.
     */
    private function recordIdempotency(string $gatewayName, WebhookPayload $payload): bool
    {
        if ($payload->eventId === null) {
            return true;
        }

        try {
            WebhookEvent::query()->create([
                'gateway' => $gatewayName,
                'event_id' => $payload->eventId,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    private function findPayment(string $gatewayName, WebhookPayload $payload): ?Payment
    {
        return Payment::query()
            ->where('gateway', $gatewayName)
            ->where('gateway_reference', $payload->gatewayReference)
            ->lockForUpdate()
            ->first();
    }

    /**
     * A settled event — succeeded or failed — is one whose loss actually matters
     * (the money moved or an attempt resolved). A miss on one of these must
     * trigger a gateway retry rather than be silently dropped (M10/D8).
     */
    private function isActionableMiss(WebhookPayload $payload): bool
    {
        return in_array($payload->status, [PaymentStatus::Succeeded, PaymentStatus::Failed], true);
    }

    private function isRefundEvent(WebhookPayload $payload): bool
    {
        return in_array(
            $payload->status,
            [PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded],
            true,
        );
    }

    /**
     * Apply a refund webhook to the payment. `amount_refunded` is set to the
     * gateway's CUMULATIVE total monotonically (never regresses on out-of-order
     * delivery), and status is recomputed FROM THE AMOUNTS — not from the event
     * type — so a partial `charge.refunded` doesn't flip the row to fully
     * Refunded while money remains. Dispatches PaymentRefunded with this event's
     * delta so Commerce records the matching Refund row.
     */
    private function applyRefund(Payment $payment, WebhookPayload $payload): void
    {
        // charge.refund.updated carries no cumulative — nothing authoritative to
        // apply; charge.refunded is the event that moves the total.
        if ($payload->amountRefunded === null) {
            return;
        }

        $newRefunded = max($payment->amount_refunded, $payload->amountRefunded);

        if ($newRefunded <= $payment->amount_refunded) {
            return; // stale or already applied — don't regress, don't re-dispatch
        }

        $delta = $newRefunded - $payment->amount_refunded;

        $status = $newRefunded >= $payment->amount
            ? PaymentStatus::Refunded
            : PaymentStatus::PartiallyRefunded;

        $payment->update([
            'amount_refunded' => $newRefunded,
            'status' => $status,
            'gateway_data' => array_merge($payment->gateway_data ?? [], $payload->gatewayData),
        ]);

        PaymentRefunded::dispatch($payment, $payload->gatewayRefundId, $delta);
    }

    /**
     * Defense-in-depth: refuse to act on a webhook whose amount or currency
     * disagrees with the Payment row we created server-side. The signature has
     * already been verified, so a mismatch implies one of: a Stripe routing
     * bug, a confused-deputy in our own code (wrong gateway_reference linked),
     * or compromised webhook secrets. In all cases we'd rather fail loudly
     * than silently mark a payment "succeeded" for the wrong amount.
     *
     * The transaction wrapping `__invoke` rolls back the idempotency row on
     * throw, so the gateway's retry will hit this same guard again until the
     * underlying mismatch is resolved or the operator acks via the gateway
     * dashboard.
     */
    private function guardAmountMatches(Payment $payment, WebhookPayload $payload): void
    {
        if ($payload->amount !== null && $payload->amount !== $payment->amount) {
            throw PaymentAmountMismatchException::amount(
                expected: $payment->amount,
                received: $payload->amount,
            );
        }

        $expectedCurrency = $payment->currency?->value;

        if (
            $payload->currency !== null
            && $expectedCurrency !== null
            && strtolower($payload->currency) !== strtolower($expectedCurrency)
        ) {
            throw PaymentAmountMismatchException::currency(
                expected: strtolower($expectedCurrency),
                received: strtolower($payload->currency),
            );
        }
    }

    private function updatePaymentStatus(Payment $payment, WebhookPayload $payload): bool
    {
        if ($payment->status === $payload->status) {
            return false;
        }

        if ($this->wouldRegressSettledPayment($payment->status, $payload->status)) {
            return false;
        }

        $payment->update([
            'status' => $payload->status,
            'gateway_data' => array_merge($payment->gateway_data ?? [], $payload->gatewayData),
        ]);

        return true;
    }

    /**
     * A settled payment (Succeeded, or already Refunded/PartiallyRefunded) must
     * never be dragged back to Failed or Pending by an out-of-order delivery — a
     * stale earlier-attempt event arriving after the money settled (M6). That
     * would strand real money as unrefundable and lie to every downstream
     * reader. Forward refund transitions are not handled here (they go through
     * applyRefund), so this only ever blocks a backwards move.
     */
    private function wouldRegressSettledPayment(PaymentStatus $current, PaymentStatus $incoming): bool
    {
        $settled = [PaymentStatus::Succeeded, PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded];
        $backwards = [PaymentStatus::Failed, PaymentStatus::Pending];

        return in_array($current, $settled, true) && in_array($incoming, $backwards, true);
    }

    private function dispatchEvent(Payment $payment): void
    {
        match ($payment->status) {
            PaymentStatus::Succeeded => PaymentSucceeded::dispatch($payment),
            PaymentStatus::Failed => PaymentFailed::dispatch($payment),
            default => null,
        };
    }
}
