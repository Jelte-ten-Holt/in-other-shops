<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Events;

use Illuminate\Foundation\Events\Dispatchable;
use InOtherShops\Commerce\Order\Models\Refund;

/**
 * A refund has been recorded against an order (the money already moved at the
 * gateway). Dispatched exactly once per refund — when the Refund row is first
 * created — so an admin refund and its echoing gateway webhook don't double-fire
 * downstream listeners (audit log, customer email). Carries the full Refund
 * record incl. reason, actor, and the reversed per-bracket tax.
 */
final readonly class RefundRecorded
{
    use Dispatchable;

    public function __construct(
        public Refund $refund,
    ) {}
}
