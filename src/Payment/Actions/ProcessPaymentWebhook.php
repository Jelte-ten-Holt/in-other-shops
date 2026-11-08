<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InOtherShops\Payment\Contracts\ListsRefunds;
use InOtherShops\Payment\Contracts\PaymentGateway;
use InOtherShops\Payment\DTOs\GatewayRefund;
use InOtherShops\Payment\DTOs\GatewayRefundFailure;
use InOtherShops\Payment\DTOs\WebhookPayload;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Events\PaymentFailed;
use InOtherShops\Payment\Events\PaymentRefunded;
use InOtherShops\Payment\Events\PaymentRefundFailed;
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

        // Authentic, and nothing this gateway acts on: no ledger row, no lock.
        if ($payload === null) {
            return null;
        }

        // Fetched BEFORE the transaction, so no gateway call ever runs under the
        // payment row lock. If it throws, nothing has been written: the delivery
        // answers non-2xx and the gateway retries it.
        $gatewayRefunds = $this->gatewayRefundsFor($gateway, $gatewayName, $payload);
        $gatewayListsRefunds = $gateway instanceof ListsRefunds;

        return DB::transaction(function () use ($gatewayName, $payload, $gatewayRefunds, $gatewayListsRefunds): ?Payment {
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

            if ($payload->refundFailure !== null) {
                $this->applyRefundFailure($payment, $payload->refundFailure, $payload, $gatewayRefunds, $gatewayListsRefunds);
            } elseif ($this->isRefundEvent($payload)) {
                $this->applyRefund($payment, $payload, $gatewayRefunds, $gatewayListsRefunds);
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
        return $this->paymentQuery($gatewayName, $payload)->lockForUpdate()->first();
    }

    /**
     * @return Builder<Payment>
     */
    private function paymentQuery(string $gatewayName, WebhookPayload $payload): Builder
    {
        return Payment::query()
            ->where('gateway', $gatewayName)
            ->where('gateway_reference', $payload->gatewayReference);
    }

    /**
     * The gateway's own refunds for the payment a refund event is about, oldest
     * first — or null, when the event cannot move the payment's refunded total.
     * The gateway is asked only when all of these hold:
     *
     * - the event reports a refund failure, or is a refund event carrying a
     *   cumulative;
     * - the gateway can list refunds;
     * - an UNLOCKED read finds the payment. One gateway account can serve more
     *   than one shop, and then every endpoint receives every shop's events;
     *   this keeps the call to payments that are ours;
     * - for a refund event, that row's refunded total is below the event's
     *   cumulative. The echo of an admin refund fails this and costs no call.
     *   A failure always asks: the list is what the lowered total is read from.
     *
     * The total can fall since failed refunds (R4), so the locked read inside
     * the transaction may find it lower than this read did: a failure landed in
     * between. {@see self::applyRefund()} then moves nothing without a list,
     * because the event predates the failure and its cumulative still counts
     * the failed refund.
     *
     * @return list<GatewayRefund>|null
     */
    private function gatewayRefundsFor(PaymentGateway $gateway, string $gatewayName, WebhookPayload $payload): ?array
    {
        $isFailure = $payload->refundFailure !== null;

        if (! $gateway instanceof ListsRefunds || (! $isFailure && (! $this->isRefundEvent($payload) || $payload->amountRefunded === null))) {
            return null;
        }

        $payment = $this->paymentQuery($gatewayName, $payload)->first();

        if ($payment === null || (! $isFailure && $payment->amount_refunded >= $payload->amountRefunded)) {
            return null;
        }

        return $gateway->listRefunds($payment);
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
     * Apply a refund webhook to the payment. `amount_refunded` only rises here
     * (an out-of-order delivery never regresses it; a failed refund lowers it
     * in {@see self::applyRefundFailure()}), and status is recomputed FROM THE
     * AMOUNTS — not from the event type — so a partial `charge.refunded` doesn't
     * flip the row to fully Refunded while money remains. When the total moves,
     * dispatches PaymentRefunded with the gateway's own refund list so Commerce
     * records every refund not yet recorded. The event does not say which
     * refund it is about, so nothing is derived from that.
     *
     * When the gateway can list refunds, the list caps the event's cumulative:
     * it was read just now, the event may be minutes old, and a refund that
     * failed since is off the list (Stripe's own total falls with it). Without
     * the cap, a late `charge.refunded` arriving after a failure would raise
     * the total back to the failed amount. No list from such a gateway means
     * the unlocked read found nothing to move, so nothing moves.
     *
     * @param  list<GatewayRefund>|null  $gatewayRefunds
     */
    private function applyRefund(Payment $payment, WebhookPayload $payload, ?array $gatewayRefunds, bool $gatewayListsRefunds): void
    {
        // A refund event without a cumulative carries nothing authoritative to
        // apply.
        if ($payload->amountRefunded === null) {
            return;
        }

        $cumulative = $payload->amountRefunded;

        if ($gatewayListsRefunds) {
            if ($gatewayRefunds === null) {
                return;
            }

            $cumulative = min($cumulative, array_sum(array_map(
                fn (GatewayRefund $refund): int => $refund->amount,
                $gatewayRefunds,
            )));
        }

        if ($cumulative <= $payment->amount_refunded) {
            return; // stale or already applied — don't regress, don't re-dispatch
        }

        $payment->update([
            'amount_refunded' => $cumulative,
            'status' => $this->statusFor($payment, $cumulative),
            'gateway_data' => array_merge($payment->gateway_data ?? [], $payload->gatewayData),
        ]);

        PaymentRefunded::dispatch($payment, $gatewayRefunds ?? []);
    }

    /**
     * A refund the gateway accepted has failed: the money is back on the shop's
     * balance and never reached the customer. Lowers the payment's refunded
     * total and recomputes the status from the amounts, back to Succeeded when
     * nothing stays refunded. Dispatches no PaymentSucceeded: the payment was
     * never unpaid. PaymentRefundFailed takes the Refund row out of the order's
     * totals on the Commerce side.
     *
     * When the gateway lists refunds, the new total is the lower of the stored
     * total and what the list still holds (without the failed refund), never a
     * subtraction. A subtraction takes off money the total may never have
     * counted: a refund event retried past the failure already left the failed
     * refund out, and subtracting it again would take a standing refund with
     * it. Read from the list, the result is the same whatever order the events
     * arrive in, and a second failure event for the same refund changes
     * nothing. A gateway that cannot list refunds falls back to subtracting the
     * failed amount, never below zero.
     *
     * @param  list<GatewayRefund>|null  $gatewayRefunds
     */
    private function applyRefundFailure(Payment $payment, GatewayRefundFailure $failure, WebhookPayload $payload, ?array $gatewayRefunds, bool $gatewayListsRefunds): void
    {
        $refunded = $gatewayListsRefunds && $gatewayRefunds !== null
            ? min($payment->amount_refunded, array_sum(array_map(
                fn (GatewayRefund $refund): int => $refund->id === $failure->id ? 0 : $refund->amount,
                $gatewayRefunds,
            )))
            : max(0, $payment->amount_refunded - $failure->amount);

        $payment->update([
            'amount_refunded' => $refunded,
            'status' => $this->statusFor($payment, $refunded),
            'gateway_data' => array_merge($payment->gateway_data ?? [], $payload->gatewayData),
        ]);

        PaymentRefundFailed::dispatch($payment, $failure);
    }

    private function statusFor(Payment $payment, int $refunded): PaymentStatus
    {
        return match (true) {
            $refunded <= 0 => PaymentStatus::Succeeded,
            $refunded >= $payment->amount => PaymentStatus::Refunded,
            default => PaymentStatus::PartiallyRefunded,
        };
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
            // Deliberately still 204s (the idempotency row is already recorded)
            // so the gateway stops redelivering a stale event — but leave a
            // trace: a refused transition is exactly the out-of-order delivery
            // an operator investigating a payment mismatch needs to see.
            Log::info('Webhook status regression refused', [
                'payment_id' => $payment->getKey(),
                'gateway_reference' => $payment->gateway_reference,
                'current' => $payment->status->value,
                'incoming' => $payload->status->value,
                'event_id' => $payload->eventId,
            ]);

            return false;
        }

        $payment->update([
            'status' => $payload->status,
            'gateway_data' => array_merge($payment->gateway_data ?? [], $payload->gatewayData),
        ]);

        return true;
    }

    /**
     * A settled payment must never move backwards on an out-of-order delivery.
     * Two shapes (both real: gateways do not guarantee event ordering):
     *
     * - Succeeded/Refunded/PartiallyRefunded dragged back to Failed or Pending
     *   by a stale earlier-attempt event (M6) — strands real money as
     *   unrefundable and lies to every downstream reader.
     * - Refunded/PartiallyRefunded dragged "forward" to Succeeded by a delayed
     *   success delivery arriving AFTER a refund was applied — would un-refund
     *   the payment and fire PaymentSucceeded (confirm + ship) for a refunded
     *   sale.
     *
     * Genuine refund transitions never pass through here — they go through
     * applyRefund — so refusing Succeeded onto a refunded payment loses nothing.
     */
    private function wouldRegressSettledPayment(PaymentStatus $current, PaymentStatus $incoming): bool
    {
        $settled = [PaymentStatus::Succeeded, PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded];
        $backwards = [PaymentStatus::Failed, PaymentStatus::Pending];

        if (in_array($current, $settled, true) && in_array($incoming, $backwards, true)) {
            return true;
        }

        $refunded = [PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded];

        return in_array($current, $refunded, true) && $incoming === PaymentStatus::Succeeded;
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
