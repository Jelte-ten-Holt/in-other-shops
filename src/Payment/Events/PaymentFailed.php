<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Events;

use InOtherShops\Payment\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A payment ATTEMPT failed — this is not a terminal state for the payment.
 *
 * For card gateways this dispatches per attempt: Stripe fires
 * `payment_intent.payment_failed` on every decline and parks the intent in
 * `requires_payment_method` — still live, still completable from the shopper's
 * open payment page, and a later attempt on the same intent can succeed (the
 * payment row then moves Failed → Succeeded and PaymentSucceeded dispatches).
 *
 * Consumer listeners must therefore NOT treat this as "the order will never be
 * paid": cancelling the order or releasing its stock here strands the money a
 * successful retry captures seconds later. Ending an abandoned Pending order is
 * `commerce:expire-orders`' job — it voids the intent and releases the stock
 * together, including intents left behind by failed attempts.
 */
final readonly class PaymentFailed
{
    use Dispatchable;

    public function __construct(
        public Payment $payment,
    ) {}
}
