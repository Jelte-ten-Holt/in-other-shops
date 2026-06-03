<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Listeners;

use InOtherShops\Logging\DTOs\LogEntry;
use InOtherShops\Logging\Enums\LogLevel;
use InOtherShops\Logging\LogDispatcher;
use InOtherShops\Purchasing\Events\ItemsReceived;
use InOtherShops\Purchasing\Events\PurchaseOrderCancelled;
use InOtherShops\Purchasing\Events\PurchaseOrderCreated;
use InOtherShops\Purchasing\Events\PurchaseOrderPlaced;
use Illuminate\Contracts\Events\Dispatcher;

final class PurchasingLogSubscriber
{
    private const string CHANNEL = 'purchasing';

    public function __construct(
        private readonly LogDispatcher $dispatcher,
    ) {}

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
        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Info,
            channel: self::CHANNEL,
            message: "Purchase order created: {$event->order->reference}.",
            context: [
                'purchase_order_id' => $event->order->id,
                'reference' => $event->order->reference,
                'supplier_id' => $event->order->supplier_id,
                'subtotal' => $event->order->subtotal,
                'total' => $event->order->total,
            ],
        ));
    }

    public function handlePurchaseOrderPlaced(PurchaseOrderPlaced $event): void
    {
        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Info,
            channel: self::CHANNEL,
            message: "Purchase order placed: {$event->order->reference}.",
            context: [
                'purchase_order_id' => $event->order->id,
                'reference' => $event->order->reference,
                'supplier_id' => $event->order->supplier_id,
            ],
        ));
    }

    public function handleItemsReceived(ItemsReceived $event): void
    {
        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Info,
            channel: self::CHANNEL,
            message: "Items received against {$event->order->reference}.",
            context: [
                'purchase_order_id' => $event->order->id,
                'reference' => $event->order->reference,
                'status' => $event->order->status->value,
                'received' => $event->received,
            ],
        ));
    }

    public function handlePurchaseOrderCancelled(PurchaseOrderCancelled $event): void
    {
        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Notice,
            channel: self::CHANNEL,
            message: "Purchase order cancelled: {$event->order->reference}.",
            context: [
                'purchase_order_id' => $event->order->id,
                'reference' => $event->order->reference,
                'supplier_id' => $event->order->supplier_id,
            ],
        ));
    }
}
