<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Listeners;

use InOtherShops\Commerce\Order\Events\OrderCreated;
use InOtherShops\Commerce\Order\Events\OrderFailed;
use InOtherShops\Commerce\Order\Events\OrderStatusChanged;
use InOtherShops\Logging\DTOs\LogEntry;
use InOtherShops\Logging\Enums\LogLevel;
use InOtherShops\Logging\LogDispatcher;
use Illuminate\Contracts\Events\Dispatcher;

final class CommerceLogSubscriber
{
    private const string CHANNEL = 'commerce';

    public function __construct(
        private readonly LogDispatcher $dispatcher,
    ) {}

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            OrderCreated::class => 'handleOrderCreated',
            OrderFailed::class => 'handleOrderFailed',
            OrderStatusChanged::class => 'handleOrderStatusChanged',
        ];
    }

    public function handleOrderCreated(OrderCreated $event): void
    {
        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Info,
            channel: self::CHANNEL,
            message: "Order {$event->order->order_number} created.",
            context: [
                'order_id' => $event->order->id,
                'order_number' => $event->order->order_number,
                'customer_id' => $event->order->customer_id,
                'total' => $event->order->total,
                'currency' => $event->order->currency?->value,
            ],
        ));
    }

    public function handleOrderFailed(OrderFailed $event): void
    {
        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Error,
            channel: self::CHANNEL,
            message: "Order failed: {$event->reason}.",
            context: [
                'reason' => $event->reason,
                'failed_step' => $event->failedStep,
            ],
        ));
    }

    public function handleOrderStatusChanged(OrderStatusChanged $event): void
    {
        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Info,
            channel: self::CHANNEL,
            message: "Order {$event->order->order_number} status: {$event->from->value} → {$event->to->value}.",
            context: [
                'order_id' => $event->order->id,
                'order_number' => $event->order->order_number,
                'from' => $event->from->value,
                'to' => $event->to->value,
            ],
        ));
    }
}
