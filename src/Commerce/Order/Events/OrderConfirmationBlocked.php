<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Events;

use InOtherShops\Commerce\Order\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A payment succeeded but the order could not be confirmed — its stock
 * reservations were released before confirmation (F14), or the order is no
 * longer in a confirmable state (e.g. Cancelled). This is a paid-but-unfulfilled
 * order that needs a human: restock + confirm, or refund. Audited via the
 * commerce log channel; consumers may also listen to alert an operator.
 */
final readonly class OrderConfirmationBlocked
{
    use Dispatchable;

    public function __construct(
        public Order $order,
        public string $reason,
    ) {}
}
