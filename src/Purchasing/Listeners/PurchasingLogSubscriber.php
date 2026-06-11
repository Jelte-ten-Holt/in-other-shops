<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Listeners;

use InOtherShops\Logging\Enums\LogLevel;
use InOtherShops\Logging\LogSubscriberBase;
use InOtherShops\Purchasing\Events\ItemsReceived;
use InOtherShops\Purchasing\Events\PurchaseOrderCancelled;
use InOtherShops\Purchasing\Events\PurchaseOrderCreated;
use InOtherShops\Purchasing\Events\PurchaseOrderPlaced;
use Illuminate\Contracts\Events\Dispatcher;

final class PurchasingLogSubscriber extends LogSubscriberBase
{
    protected const string CHANNEL = 'purchasing';

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            PurchaseOrderCreated::class => 'handlePurchaseOrderCreated',
            PurchaseOrderPlaced::class => 'handlePurchaseOrderPlaced',
            ItemsReceived::class => 'handleItemsReceived',
            PurchaseOrderCancelled::class => 'handlePurchaseOrderCancelled',
        ];
    }

    public function handlePurchaseOrderCreated(PurchaseOrderCreated $event): void
    {
        $this->log(LogLevel::Info, "Purchase order created: {$event->order->reference}.", [
                'purchase_order_id' => $event->order->id,
                'reference' => $event->order->reference,
                'supplier_id' => $event->order->supplier_id,
                'subtotal' => $event->order->subtotal,
                'total' => $event->order->total,
            ]);
    }

    public function handlePurchaseOrderPlaced(PurchaseOrderPlaced $event): void
    {
        $this->log(LogLevel::Info, "Purchase order placed: {$event->order->reference}.", [
                'purchase_order_id' => $event->order->id,
                'reference' => $event->order->reference,
                'supplier_id' => $event->order->supplier_id,
            ]);
    }

    public function handleItemsReceived(ItemsReceived $event): void
    {
        $this->log(LogLevel::Info, "Items received against {$event->order->reference}.", [
                'purchase_order_id' => $event->order->id,
                'reference' => $event->order->reference,
                'status' => $event->order->status->value,
                'received' => $event->received,
            ]);
    }

    public function handlePurchaseOrderCancelled(PurchaseOrderCancelled $event): void
    {
        $this->log(LogLevel::Notice, "Purchase order cancelled: {$event->order->reference}.", [
                'purchase_order_id' => $event->order->id,
                'reference' => $event->order->reference,
                'supplier_id' => $event->order->supplier_id,
            ]);
    }
}
