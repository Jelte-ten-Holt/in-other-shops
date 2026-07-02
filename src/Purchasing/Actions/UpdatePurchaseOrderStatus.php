<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Actions;

use InOtherShops\Purchasing\Enums\PurchaseOrderStatus;
use InOtherShops\Purchasing\Exceptions\InvalidPurchaseOrderTransitionException;
use InOtherShops\Purchasing\Models\PurchaseOrder;

/**
 * Internal transition helper. Prefer the typed actions.
 *
 * Every purchase-order status write routes through here so the state machine
 * (PurchaseOrderStatus::allowedTransitions) is authoritative — no writer can
 * sidestep it with a bare update(['status' => ...]).
 */
final class UpdatePurchaseOrderStatus
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(PurchaseOrder $order, PurchaseOrderStatus $newStatus, array $attributes = []): PurchaseOrder
    {
        if (! $order->status->canTransitionTo($newStatus)) {
            throw InvalidPurchaseOrderTransitionException::between($order->status, $newStatus);
        }

        $order->update(['status' => $newStatus, ...$attributes]);

        return $order;
    }
}
