<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Actions;

use InOtherShops\Purchasing\Enums\PurchaseOrderStatus;
use InOtherShops\Purchasing\Events\PurchaseOrderCancelled;
use InOtherShops\Purchasing\Exceptions\InvalidPurchaseOrderTransitionException;
use InOtherShops\Purchasing\Models\PurchaseOrder;

/**
 * Cancel a purchase order. Already-received stock is intentionally NOT reversed
 * — the goods physically arrived; cancellation only blocks further receipts.
 * A fully-received order cannot be cancelled.
 */
final class CancelPurchaseOrder
{
    public function __invoke(PurchaseOrder $order): PurchaseOrder
    {
        if (! $order->status->canTransitionTo(PurchaseOrderStatus::Cancelled)) {
            throw InvalidPurchaseOrderTransitionException::between($order->status, PurchaseOrderStatus::Cancelled);
        }

        $order->update(['status' => PurchaseOrderStatus::Cancelled]);

        PurchaseOrderCancelled::dispatch($order);

        return $order;
    }
}
