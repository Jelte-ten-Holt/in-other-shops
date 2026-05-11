<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Order;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Shipping\Enums\ShipmentStatus;
use InOtherShops\Shipping\Models\Shipment;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class OrderIsCompleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('shipping.auto_create_shipment', false);
    }

    #[Test]
    public function pending_order_is_not_complete(): void
    {
        $order = Order::factory()->status(OrderStatus::Pending)->create();

        $this->assertFalse($order->isComplete());
    }

    #[Test]
    public function confirmed_paid_order_with_zero_shipment_records_is_not_complete(): void
    {
        // Narrow scope: a confirmed, paid Order with no Shipment rows in the
        // DB is currently never `isComplete()`. The package has no notion of
        // "digital order = complete on payment" — a future digital-goods
        // surface would either create a Delivered Shipment on confirm or
        // bypass the shipment check, and would need its own test.
        $order = $this->paidOrder();

        $this->assertFalse($order->isComplete());
    }

    #[Test]
    public function confirmed_paid_order_with_pending_shipment_is_not_complete(): void
    {
        $order = $this->paidOrder();
        Shipment::factory()->status(ShipmentStatus::Pending)->for($order, 'shippable')->create();

        $this->assertFalse($order->fresh()->isComplete());
    }

    #[Test]
    public function confirmed_paid_order_with_all_delivered_shipments_is_complete(): void
    {
        $order = $this->paidOrder();
        Shipment::factory()->status(ShipmentStatus::Delivered)->for($order, 'shippable')->create();

        $this->assertTrue($order->fresh()->isComplete());
    }

    #[Test]
    public function order_with_one_undelivered_shipment_is_not_complete(): void
    {
        $order = $this->paidOrder();
        Shipment::factory()->status(ShipmentStatus::Delivered)->for($order, 'shippable')->create();
        Shipment::factory()->status(ShipmentStatus::InTransit)->for($order, 'shippable')->create();

        $this->assertFalse($order->fresh()->isComplete());
    }

    #[Test]
    public function unpaid_order_with_delivered_shipment_is_not_complete(): void
    {
        $order = Order::factory()->status(OrderStatus::Confirmed)->create(['total' => 1000, 'currency' => Currency::EUR]);
        Shipment::factory()->status(ShipmentStatus::Delivered)->for($order, 'shippable')->create();

        $this->assertFalse($order->fresh()->isComplete());
    }

    private function paidOrder(): Order
    {
        $order = Order::factory()
            ->status(OrderStatus::Confirmed)
            ->create(['total' => 1000, 'currency' => Currency::EUR]);

        $order->payments()->create([
            'gateway' => 'fake',
            'status' => PaymentStatus::Succeeded,
            'amount' => 1000,
            'amount_refunded' => 0,
            'currency' => Currency::EUR,
        ]);

        return $order;
    }
}
