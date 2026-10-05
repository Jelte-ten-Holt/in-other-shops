<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Events;

use InOtherShops\Payment\DTOs\GatewayRefund;
use InOtherShops\Payment\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A refund event from the gateway moved a payment's refunded total: a refund
 * made in the Stripe dashboard, or through the API by anyone but this app.
 * Carries the gateway's own list of refunds for the payment, oldest first, so
 * the Commerce reconciliation listener can record every one not yet recorded.
 * The list is empty when the gateway cannot list refunds. The admin path does
 * NOT dispatch this — it records the Refund row directly through RefundOrder;
 * this event is the gateway→Commerce bridge for refunds that originate outside
 * the app.
 */
final readonly class PaymentRefunded
{
    use Dispatchable;

    /**
     * @param  list<GatewayRefund>  $gatewayRefunds
     */
    public function __construct(
        public Payment $payment,
        public array $gatewayRefunds = [],
    ) {}
}
