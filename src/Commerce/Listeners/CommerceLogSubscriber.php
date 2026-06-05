<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Listeners;

use InOtherShops\Commerce\Cart\Events\CartClaimed;
use InOtherShops\Commerce\Cart\Events\CartCleared;
use InOtherShops\Commerce\Cart\Events\CartUpdated;
use InOtherShops\Commerce\Order\Events\OrderConfirmationBlocked;
use InOtherShops\Commerce\Order\Events\OrderCreated;
use InOtherShops\Commerce\Order\Events\OrderStatusChanged;
use InOtherShops\Commerce\Order\Events\RefundRecorded;
use InOtherShops\Commerce\Order\Enums\RefundActorSource;
use InOtherShops\Commerce\Order\Models\Refund;
use InOtherShops\Logging\DTOs\LogActor;
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
            CartUpdated::class => 'handleCartUpdated',
            CartClaimed::class => 'handleCartClaimed',
            CartCleared::class => 'handleCartCleared',
            OrderCreated::class => 'handleOrderCreated',
            OrderStatusChanged::class => 'handleOrderStatusChanged',
            OrderConfirmationBlocked::class => 'handleOrderConfirmationBlocked',
            RefundRecorded::class => 'handleRefundRecorded',
        ];
    }

    public function handleOrderConfirmationBlocked(OrderConfirmationBlocked $event): void
    {
        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Warning,
            channel: self::CHANNEL,
            message: "Order #{$event->order->getKey()} paid but not confirmed: {$event->reason}.",
            context: [
                'order_id' => $event->order->getKey(),
                'status' => $event->order->status->value,
                'reason' => $event->reason,
            ],
        ));
    }

    public function handleRefundRecorded(RefundRecorded $event): void
    {
        $refund = $event->refund;

        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Info,
            channel: self::CHANNEL,
            message: "Refund of {$refund->amount} recorded on order {$refund->order_id}.",
            context: [
                'refund_id' => $refund->id,
                'order_id' => $refund->order_id,
                'payment_id' => $refund->payment_id,
                'gateway' => $refund->gateway,
                'gateway_refund_id' => $refund->gateway_refund_id,
                'amount' => $refund->amount,
                'reason' => $refund->reason,
                'actor_source' => $refund->actor_source->value,
                'actor_id' => $refund->actor_id,
                'actor_label' => $refund->actor_label,
            ],
            // A refund knows its own actor better than the ambient request does
            // (a gateway-initiated refund has no operator), so it carries one
            // explicitly — derived from the durable RefundActor so the audit
            // actor and the business record never disagree (brief, §4).
            actor: $this->auditActorForRefund($refund),
        ));
    }

    /**
     * Map the refund's business {@see RefundActor} onto the cross-cutting audit
     * {@see LogActor}: an admin-issued refund is a User actor; a gateway-issued
     * one (Stripe dashboard, dispute auto-refund) is a Gateway actor named for
     * the gateway. Lives here, not on LogActor, so the Logging domain stays
     * independent of Commerce.
     */
    private function auditActorForRefund(Refund $refund): LogActor
    {
        return match ($refund->actor_source) {
            RefundActorSource::Admin => LogActor::user(
                (string) ($refund->actor_id ?? ''),
                $refund->actor_label ?? 'admin',
            ),
            RefundActorSource::Gateway => LogActor::gateway($refund->gateway ?? 'gateway'),
        };
    }

    public function handleCartUpdated(CartUpdated $event): void
    {
        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Info,
            channel: self::CHANNEL,
            message: 'Cart updated.',
            context: $this->cartContext($event->cart),
        ));
    }

    public function handleCartClaimed(CartClaimed $event): void
    {
        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Info,
            channel: self::CHANNEL,
            message: 'Cart claimed.',
            context: [
                ...$this->cartContext($event->cart),
                'new_owner_type' => $event->owner->getMorphClass(),
                'new_owner_id' => $event->owner->getKey(),
            ],
        ));
    }

    public function handleCartCleared(CartCleared $event): void
    {
        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Info,
            channel: self::CHANNEL,
            message: 'Cart cleared.',
            context: $this->cartContext($event->cart),
        ));
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

    /** @return array<string, mixed> */
    private function cartContext(\InOtherShops\Commerce\Cart\Models\Cart $cart): array
    {
        return [
            'cart_id' => $cart->id,
            'owner_type' => $cart->owner_type,
            'owner_id' => $cart->owner_id,
            'item_count' => $cart->items()->count(),
        ];
    }
}
