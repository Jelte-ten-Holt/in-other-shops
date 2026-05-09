<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Shipping;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Shipping\Actions\CreateShipment;
use InOtherShops\Shipping\Enums\ShipmentStatus;
use InOtherShops\Shipping\Events\ShipmentCreated;
use InOtherShops\Shipping\ShippingConfig;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class CreateShipmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('shipping.auto_create_shipment', false);
        config()->set('shipping.zones', [
            'de' => ['name' => 'Germany', 'currency' => 'EUR', 'countries' => ['DE']],
        ]);
        config()->set('shipping.methods', [
            'express' => ['name' => 'Express', 'rates' => ['de' => 999]],
        ]);
    }

    #[Test]
    public function it_creates_a_pending_shipment_attached_to_the_order(): void
    {
        $order = Order::factory()->withLines(2)->create();
        $method = ShippingConfig::method('express');

        $shipment = (new CreateShipment)($order, $method);

        $this->assertSame('express', $shipment->method);
        $this->assertSame(ShipmentStatus::Pending, $shipment->status);
        $this->assertSame($order->id, $shipment->shippable_id);
        $this->assertSame($order->getMorphClass(), $shipment->shippable_type);
    }

    #[Test]
    public function it_attaches_all_order_lines_by_default(): void
    {
        $order = Order::factory()->withLines(3)->create();
        $method = ShippingConfig::method('express');

        $shipment = (new CreateShipment)($order, $method);

        $this->assertCount(3, $shipment->items);
        $this->assertEqualsCanonicalizing(
            $order->lines->pluck('id')->all(),
            $shipment->items->pluck('order_line_id')->all(),
        );
    }

    #[Test]
    public function it_attaches_a_subset_of_order_lines_when_provided(): void
    {
        $order = Order::factory()->withLines(3)->create();
        $method = ShippingConfig::method('express');
        $subset = $order->lines->take(2);

        $shipment = (new CreateShipment)($order, $method, $subset);

        $this->assertCount(2, $shipment->items);
        $this->assertEqualsCanonicalizing(
            $subset->pluck('id')->all(),
            $shipment->items->pluck('order_line_id')->all(),
        );
    }

    #[Test]
    public function it_records_quantity_from_each_order_line(): void
    {
        $order = Order::factory()->withLines(1)->create();
        $line = $order->lines->first();
        $method = ShippingConfig::method('express');

        $shipment = (new CreateShipment)($order, $method);

        $this->assertSame($line->quantity, $shipment->items->first()->quantity);
    }

    #[Test]
    public function it_dispatches_a_shipment_created_event(): void
    {
        Event::fake([ShipmentCreated::class]);

        $order = Order::factory()->withLines(1)->create();
        $method = ShippingConfig::method('express');

        $shipment = (new CreateShipment)($order, $method);

        Event::assertDispatched(
            ShipmentCreated::class,
            fn (ShipmentCreated $event) => $event->shipment->is($shipment),
        );
    }
}
