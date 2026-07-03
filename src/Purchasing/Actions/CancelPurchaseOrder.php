<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Actions;

use InOtherShops\Purchasing\Enums\PurchaseOrderStatus;
use InOtherShops\Purchasing\Events\PurchaseOrderCancelled;
use InOtherShops\Purchasing\Models\PurchaseOrder;

/**
 * Cancel a purchase order. Already-received stock is intentionally NOT reversed
 * — the goods physically arrived; cancellation only blocks further receipts.
 * A fully-received order cannot be cancelled.
 */
final class CancelPurchaseOrder
{
    public function __construct(
        private readonly UpdatePurchaseOrderStatus $updateStatus,
    ) {}

    public function __invoke(PurchaseOrder $order): PurchaseOrder
    {
        ($this->updateStatus)($order, PurchaseOrderStatus::Cancelled);

        PurchaseOrderCancelled::dispatch($order);

        return $order;
    }
}
