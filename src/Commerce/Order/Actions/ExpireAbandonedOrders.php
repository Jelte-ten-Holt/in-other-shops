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
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cancel Pending orders that were never paid within their hold window — the
 * order-side half of closing F14. Couples reservation release to order
 * cancellation (they were decoupled: the reservation-expiry cron released stock
 * but left the order Pending and the gateway intent live, so a late payment
 * could confirm an order whose stock was already gone).
 *
 * For each abandoned order, in two phases:
 *  1. (no lock held) re-check it is still Pending and unpaid, then cancel its
 *     gateway intent(s) FIRST — this is what makes a late payment impossible. If
 *     the gateway refuses because the intent is live (succeeded / processing),
 *     abort this order: the money may yet move, so leave it for the confirm path
 *     rather than cancelling an order that is actually being paid. The gateway
 *     round-trip runs OUTSIDE the order transaction, so the row lock is never
 *     held across a network call (which would widen the lock-wait/deadlock
 *     window against {@see ConfirmOrder} and let a slow gateway stall the sweep).
 *  2. (short locked transaction) re-check under the lock and transition
 *     Pending → Cancelled, which releases the reservations via the existing
 *     OrderStatusChanged → SyncInventoryOnOrderStatusChange listener.
 *
 * The two phases stay correct because a *successful* phase-1 cancel guarantees
 * no payment can subsequently succeed on that intent — so {@see ConfirmOrder}
 * (driven only by a payment success) cannot confirm between the cancel and the
 * lock. The phase-2 lock still serialises the transition itself: whichever of
 * expiry/confirm acquires it first wins; the other re-reads and no-ops.
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
            // One order that throws — a gateway outage, an unexpected driver
            // error — must not starve the sweep and leave every later order
            // stranded. Log it and move on; the next run retries it.
            try {
                if ($this->expireOne((int) $orderId)) {
                    $cancelled++;
                }
            } catch (Throwable $e) {
                Log::error('expire-orders: skipping an order that threw during expiry.', [
                    'order_id' => $orderId,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
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
        // Phase 1 — cancel the gateway intent(s) without holding the order lock.
        /** @var Order|null $order */
        $order = Commerce::order()::query()->find($orderId);

        if ($order === null || $order->status !== OrderStatus::Pending) {
            return false; // raced — confirmed or cancelled since listing
        }

        if ($this->hasSucceededPayment($order)) {
            return false; // actually paid — must not expire
        }

        try {
            $this->cancelGatewaySessions($order);
        } catch (PaymentNotCancelableException $e) {
            // Intent is live — leave the order for the confirm path. Warn with
            // the order id + reference(s): a paid-but-still-Pending order that
            // keeps failing to expire is an operator signal, not silent.
            Log::warning('expire-orders: order left Pending — its gateway intent is live.', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'references' => $order->payments()
                    ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Failed->value])
                    ->whereNotNull('gateway_reference')
                    ->pluck('gateway_reference')->all(),
                'reason' => $e->getMessage(),
            ]);

            return false;
        }

        // Phase 2 — short locked transaction: re-check and transition. The
        // intents are now cancelled, so no payment can succeed in this gap; the
        // lock only needs to cover the transition itself.
        return DB::transaction(function () use ($orderId): bool {
            /** @var Order|null $order */
            $order = Commerce::order()::query()->lockForUpdate()->find($orderId);

            if ($order === null || $order->status !== OrderStatus::Pending) {
                return false; // raced after the cancel — confirm/expiry won the lock
            }

            if ($this->hasSucceededPayment($order)) {
                return false; // an unexpected success — be safe, don't cancel
            }

            ($this->updateOrderStatus)($order, OrderStatus::Cancelled);

            return true;
        });
    }

    private function hasSucceededPayment(Order $order): bool
    {
        return $order->payments()->where('status', PaymentStatus::Succeeded->value)->exists();
    }

    /**
     * Failed is included alongside Pending because a failed ATTEMPT is not a
     * terminal intent state: after a card decline the gateway parks the intent
     * in requires_payment_method, still live and still completable from the
     * shopper's open payment page. An order abandoned after a decline therefore
     * carries a Failed payment with a retryable intent — skipping it here would
     * cancel the order while leaving the intent chargeable, and a late retry
     * would capture money against a Cancelled order (the exact decoupling this
     * action exists to prevent).
     */
    private function cancelGatewaySessions(Order $order): void
    {
        $payments = $order->payments()
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Failed->value])
            ->whereNotNull('gateway_reference')
            ->get();

        foreach ($payments as $payment) {
            $this->gateways->gateway($payment->gateway)->cancelSession($payment);
        }
    }
}
