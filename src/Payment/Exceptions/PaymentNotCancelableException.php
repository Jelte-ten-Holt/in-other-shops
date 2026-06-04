<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Exceptions;

use InOtherShops\Payment\Models\Payment;

/**
 * Thrown when a gateway refuses to cancel a payment's session/intent because the
 * payment is live (already succeeded, capturing, or otherwise in flight). The
 * caller — typically order-expiry — must treat this as "the money may yet move,
 * do NOT abandon the order," and leave the order/payment for the confirm path to
 * resolve.
 */
final class PaymentNotCancelableException extends PaymentException
{
    public static function inFlight(Payment $payment, ?string $detail = null): self
    {
        $suffix = $detail === null ? '' : " ({$detail})";

        return new self("Payment [{$payment->id}] cannot be cancelled — it is live at the gateway{$suffix}.");
    }
}
