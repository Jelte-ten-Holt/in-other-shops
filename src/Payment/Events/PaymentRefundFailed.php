<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Events;

use Illuminate\Foundation\Events\Dispatchable;
use InOtherShops\Payment\DTOs\GatewayRefundFailure;
use InOtherShops\Payment\Models\Payment;

/**
 * A refund on this payment failed at the gateway after it had been accepted,
 * and the payment's refunded total has been lowered by its amount. The
 * gateway→Commerce bridge for the failure, like {@see PaymentRefunded} is for
 * the refund: the Commerce listener marks the order's Refund row failed, which
 * takes it out of the order's refunded total and its VAT reversal.
 */
final readonly class PaymentRefundFailed
{
    use Dispatchable;

    public function __construct(
        public Payment $payment,
        public GatewayRefundFailure $refund,
    ) {}
}
