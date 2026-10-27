<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Contracts;

use InOtherShops\Payment\DTOs\Settlement;
use InOtherShops\Payment\Models\Payment;

/**
 * A gateway that can say what a payment or a refund settled as in the shop's
 * balance there. `commerce:record-settlements` asks, on the package's schedule,
 * about settled rows that have none yet.
 *
 * Null means not settled yet: ask again later. Stripe's asynchronous capture
 * leaves a card payment's balance transaction empty for up to an hour after the
 * payment succeeded, which is why this is asked on a schedule and not when the
 * success webhook arrives. Both methods call the gateway; callers never hold a
 * lock while they do.
 */
interface ReportsSettlements
{
    public function settlementFor(Payment $payment): ?Settlement;

    public function refundSettlementFor(string $gatewayRefundId): ?Settlement;
}
