<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Events;

use InOtherShops\Payment\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A refund landed on a payment via the gateway webhook (Stripe dashboard /
 * dispute / API refund). Carries the specific refund's gateway id and amount so
 * the Commerce reconciliation listener can record the matching Refund row. The
 * admin path does NOT dispatch this — it records the Refund row directly through
 * RefundOrder; this event is the gateway→Commerce bridge for refunds that
 * originate outside the app.
 */
final readonly class PaymentRefunded
{
    use Dispatchable;

    public function __construct(
        public Payment $payment,
        public ?string $gatewayRefundId = null,
        public ?int $refundAmount = null,
    ) {}
}
