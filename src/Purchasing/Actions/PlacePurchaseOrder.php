<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Actions;

use InOtherShops\Purchasing\Enums\PurchaseOrderStatus;
use InOtherShops\Purchasing\Events\PurchaseOrderPlaced;
use InOtherShops\Purchasing\Models\PurchaseOrder;

/**
 * Move a draft purchase order to Ordered (it has been placed with the supplier),
 * stamping `ordered_at`. Stock is unaffected — that happens on receipt.
 */
final class PlacePurchaseOrder
{
    public function __construct(
        private readonly UpdatePurchaseOrderStatus $updateStatus,
    ) {}

    public function __invoke(PurchaseOrder $order): PurchaseOrder
    {
        ($this->updateStatus)($order, PurchaseOrderStatus::Ordered, ['ordered_at' => now()]);

        PurchaseOrderPlaced::dispatch($order);

        return $order;
    }
}
