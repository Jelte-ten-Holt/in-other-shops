<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Actions;

use InOtherShops\Commerce\Commerce;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Exceptions\PaymentNotCancelableException;
use InOtherShops\Payment\PaymentGatewayManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cancel Pending orders that were never paid within their hold window — the
 * order-side half of closing F14. Couples reservation release to order
 * cancellation (they were decoupled: the reservation-expiry cron released stock
 * but left the order Pending and the gateway intent live, so a late payment
 * could confirm an order whose stock was already gone).
 *
 * For each abandoned order, in one locked transaction:
 *  1. re-check it is still Pending and still unpaid (it may have been confirmed
 *     or cancelled since it was listed);
 *  2. cancel its gateway intent(s) FIRST — this is what makes a late payment
 *     impossible. If the gateway refuses because the intent is live (succeeded /
 *     processing), abort this order: the money may yet move, so leave it for the
 *     confirm path rather than cancelling an order that is actually being paid;
 *  3. transition Pending → Cancelled, which releases the reservations via the
 *     existing OrderStatusChanged → SyncInventoryOnOrderStatusChange listener.
 *
 * The order lock makes this serialise against {@see ConfirmOrder}: whichever
 * acquires the lock first wins; the other re-reads and no-ops.
 */
final class ExpireAbandonedOrders
{
    public function __construct(
        private readonly UpdateOrderStatus $updateOrderStatus,
        private readonly PaymentGatewayManager $gateways,
    ) {}

    /**
     * @param  int|null  $olderThanMinutes  hold window; defaults to config
     * @return int  number of orders cancelled
     */
    public function __invoke(?int $olderThanMinutes = null): int
    {
        $minutes = $olderThanMinutes ?? (int) config('commerce.order.abandon_after_minutes', 60);
        $cutoff = Carbon::now()->subMinutes(max(1, $minutes));

        $cancelled = 0;

        foreach ($this->candidateIds($cutoff) as $orderId) {
            if ($this->expireOne((int) $orderId)) {
                $cancelled++;
            }
        }

        return $cancelled;
    }

    /**
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function candidateIds(Carbon $cutoff): \Illuminate\Support\Collection
    {
        return Commerce::order()::query()
            ->where('status', OrderStatus::Pending->value)
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->pluck('id');
    }

    private function expireOne(int $orderId): bool
    {
        return DB::transaction(function () use ($orderId): bool {
            /** @var Order|null $order */
            $order = Commerce::order()::query()->lockForUpdate()->find($orderId);

            if ($order === null || $order->status !== OrderStatus::Pending) {
                return false; // raced — confirmed or cancelled since listing
            }

            if ($order->payments()->where('status', PaymentStatus::Succeeded->value)->exists()) {
                return false; // actually paid — must not expire
            }

            try {
                $this->cancelGatewaySessions($order);
            } catch (PaymentNotCancelableException) {
                return false; // intent is live — leave the order for the confirm path
            }

            ($this->updateOrderStatus)($order, OrderStatus::Cancelled);

            return true;
        });
    }

    private function cancelGatewaySessions(Order $order): void
    {
        $payments = $order->payments()
            ->where('status', PaymentStatus::Pending->value)
            ->whereNotNull('gateway_reference')
            ->get();

        foreach ($payments as $payment) {
            $this->gateways->gateway($payment->gateway)->cancelSession($payment);
        }
    }
}
