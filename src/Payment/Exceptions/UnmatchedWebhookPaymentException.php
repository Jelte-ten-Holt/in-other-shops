<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Exceptions;

/**
 * Thrown when a settled webhook event (a succeeded or failed payment) matches no
 * payment row for its gateway_reference (M10 / D8). Under persist-then-pay the
 * reference is written by the pay page, so such an event can legitimately arrive
 * BEFORE the reference lands — and the delivery must NOT be answered 2xx, or the
 * gateway (Stripe) treats it as handled and never retries, losing a real payment
 * event forever (order stuck Pending, customer charged).
 *
 * Letting this propagate produces a non-2xx, which is exactly what tells the
 * gateway to retry. It is thrown BEFORE the idempotency row is recorded, so a
 * retry can still land once the reference exists.
 */
final class UnmatchedWebhookPaymentException extends PaymentException
{
    public static function forReference(string $gateway, ?string $reference): self
    {
        return new self(
            "No payment found for gateway [{$gateway}] reference [".($reference ?? 'null')."] on a settled webhook event.",
        );
    }
}
