<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Actions;

use InOtherShops\Commerce\Exceptions\CommerceException;
use InOtherShops\Commerce\Order\DTOs\RefundActor;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Commerce\Order\Models\Refund;
use InOtherShops\Inventory\Actions\ReleaseReservation;
use InOtherShops\Inventory\Enums\ReservationStatus;
use InOtherShops\Inventory\Inventory;
use InOtherShops\Inventory\Models\StockReservation;
use InOtherShops\Payment\Actions\RefundPayment;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Models\Payment;

/**
 * Admin entry point for refunding an order. Orchestrates the money (Payment),
 * the record + tax reversal (Commerce RecordRefund), and the restock:
 *
 * - cancelOrder = true  → transitions the order to Cancelled, and the existing
 *   SyncInventoryOnOrderStatusChange blanket-releases every reservation. The
 *   per-line picker is unused in this mode (and must be empty).
 * - cancelOrder = false → releases exactly the picked reservations (whole-line),
 *   asserting each pick actually released — a pick that released nothing (already
 *   gone via cron) is surfaced, not silently passed.
 *
 * Steps commit independently (gateway → record → restock), so a restock failure
 * cannot roll back a successful gateway charge into a record-less state; the
 * money + Refund row are durable before any restock runs.
 */
final class RefundOrder
{
    public function __construct(
        private readonly RefundPayment $refundPayment,
        private readonly RecordRefund $recordRefund,
        private readonly UpdateOrderStatus $updateOrderStatus,
        private readonly ReleaseReservation $releaseReservation,
    ) {}

    /**
     * @param  list<int>  $restockReservationIds  whole reservations to restock (non-cancel mode)
     */
    public function __invoke(
        Order $order,
        RefundActor $actor,
        ?int $amount = null,
        ?string $reason = null,
        array $restockReservationIds = [],
        bool $cancelOrder = false,
    ): Refund {
        if ($cancelOrder && $restockReservationIds !== []) {
            throw new CommerceException(
                'Cancelling an order restocks every reservation — do not also pass per-line restock picks.'
            );
        }

        $payment = $this->resolveRefundablePayment($order);

        $result = ($this->refundPayment)($payment, $amount);

        $refund = ($this->recordRefund)(
            order: $order,
            payment: $payment,
            gatewayRefundId: $result->gatewayRefundId,
            amount: $result->amount,
            cumulativeRefunded: $result->cumulativeRefunded,
            actor: $actor,
            reason: $reason,
        );

        if ($cancelOrder) {
            $this->cancel($order);
        } else {
            $this->restock($order, $restockReservationIds);
        }

        return $refund;
    }

    private function resolveRefundablePayment(Order $order): Payment
    {
        /** @var Payment|null $payment */
        $payment = $order->payments()
            ->whereIn('status', [PaymentStatus::Succeeded, PaymentStatus::PartiallyRefunded])
            ->latest()
            ->first();

        if ($payment === null) {
            throw new CommerceException("Order {$order->order_number} has no refundable payment.");
        }

        return $payment;
    }

    private function cancel(Order $order): void
    {
        if ($order->status === OrderStatus::Cancelled) {
            return;
        }

        ($this->updateOrderStatus)($order, OrderStatus::Cancelled);
    }

    /**
     * @param  list<int>  $reservationIds
     */
    private function restock(Order $order, array $reservationIds): void
    {
        if ($reservationIds === []) {
            return;
        }

        $reservations = $this->refundableReservations($order, $reservationIds);

        if ($reservations->count() !== count(array_unique($reservationIds))) {
            throw new CommerceException(
                'One or more picked reservations cannot be restocked (already released, or not on this order).'
            );
        }

        foreach ($reservations as $reservation) {
            ($this->releaseReservation)($reservation, "Refund restock for order {$order->order_number}");
        }
    }

    /**
     * @param  list<int>  $reservationIds
     * @return \Illuminate\Support\Collection<int, StockReservation>
     */
    private function refundableReservations(Order $order, array $reservationIds): \Illuminate\Support\Collection
    {
        $model = Inventory::stockReservation();

        return $model::query()
            ->whereIn('id', $reservationIds)
            ->where('reference_type', $order->getMorphClass())
            ->where('reference_id', $order->getKey())
            ->whereIn('status', [ReservationStatus::Pending, ReservationStatus::Confirmed])
            ->get();
    }
}
