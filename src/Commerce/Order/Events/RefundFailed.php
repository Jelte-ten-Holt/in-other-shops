<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Events;

use Illuminate\Foundation\Events\Dispatchable;
use InOtherShops\Commerce\Order\Models\Refund;

/**
 * A recorded refund failed at the gateway: the customer never got the money
 * and it is back on the shop's balance. The Refund row now carries `failed_at`
 * and the gateway's `failure_reason`, and counts toward neither the order's
 * refunded total nor its VAT reversal. Dispatched once per refund, on the
 * change into failed. The counterpart of {@see RefundRecorded}: a consumer that
 * told the customer about the refund, or wants the operator alerted, listens
 * here.
 */
final readonly class RefundFailed
{
    use Dispatchable;

    public function __construct(
        public Refund $refund,
    ) {}
}
