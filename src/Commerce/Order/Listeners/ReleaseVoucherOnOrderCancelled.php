<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Listeners;

use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Events\OrderStatusChanged;
use InOtherShops\Pricing\Actions\ReleaseVoucher;

/**
 * Gives back the voucher use of an order that was never paid, so an abandoned
 * checkout does not eat a campaign's uses — the voucher-side counterpart of
 * SyncInventoryOnOrderStatusChange releasing that order's stock reservations,
 * and it fires on every path that cancels a Pending order: the expiry sweep, a
 * consumer's cancel-and-replace, an admin cancelling from Filament.
 *
 * PENDING → Cancelled ONLY, deliberately narrower than the inventory listener's
 * `* → Cancelled`. A Confirmed order that is later cancelled was PAID; whether
 * its voucher use comes back is a refund policy decision (the money is being
 * returned through a different mechanism), not a checkout one. Handing the use
 * back here would silently make that decision for every consumer.
 */
final class ReleaseVoucherOnOrderCancelled
{
    public function __construct(
        private readonly ReleaseVoucher $releaseVoucher,
    ) {}

    public function handle(OrderStatusChanged $event): void
    {
        if ($event->from !== OrderStatus::Pending || $event->to !== OrderStatus::Cancelled) {
            return;
        }

        $code = $event->order->voucher_code;

        if ($code === null || $code === '') {
            return;
        }

        ($this->releaseVoucher)($code);
    }
}
