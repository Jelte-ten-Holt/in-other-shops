<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Shipping;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use InOtherShops\Commerce\Order\Events\OrderCreated;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Shipping\Enums\ShipmentStatus;
use InOtherShops\Shipping\Events\ShipmentCreated;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class AutoCreateShipmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('shipping.zones', [
            'de' => ['name' => 'Germany', 'currency' => 'EUR', 'countries' => ['DE']],
        ]);
        config()->set('shipping.methods', [
            'standard' => ['name' => 'Standard', 'rates' => ['de' => 595]],
        ]);
    }

    #[Test]
    public function it_creates_a_shipment_when_an_order_is_created_with_a_shipping_method(): void
    {
        $order = Order::factory()->withLines(2)->create([
            'shipping_method_identifier' => 'standard',
        ]);

        OrderCreated::dispatch($order);

        $shipments = $order->shipments()->get();
        $this->assertCount(1, $shipments);
        $shipment = $shipments->first();
        $this->assertSame(ShipmentStatus::Pending, $shipment->status);
        $this->assertSame('standard', $shipment->method);

        // The listener docblock claims it covers ALL of the order's lines.
        // Without this assertion a regression to "first line only" would be invisible.
        $this->assertSame(
            $order->lines()->pluck('id')->sort()->values()->all(),
            $shipment->items()->pluck('order_line_id')->sort()->values()->all(),
        );
    }

    #[Test]
    public function it_dispatches_shipment_created_with_the_new_shipment(): void
    {
        Event::fake([ShipmentCreated::class]);

        $order = Order::factory()->withLines(1)->create([
            'shipping_method_identifier' => 'standard',
        ]);

        OrderCreated::dispatch($order);

        Event::assertDispatched(
            ShipmentCreated::class,
            fn (ShipmentCreated $event) => $event->shipment->method === 'standard'
                && $event->shipment->status === ShipmentStatus::Pending,
        );
    }

    #[Test]
    public function it_skips_silently_when_no_shipping_methods_are_configured(): void
    {
        // Different from the unknown-method case: when methods is empty entirely
        // the listener returns silently rather than throwing. Otherwise consumers
        // who haven't published shipping config would crash on every order.
        config()->set('shipping.methods', []);

        $order = Order::factory()->withLines(1)->create([
            'shipping_method_identifier' => 'standard',
        ]);

        OrderCreated::dispatch($order);

        $this->assertCount(0, $order->shipments()->get());
    }

    #[Test]
    public function it_skips_when_order_has_no_shipping_method(): void
    {
        $order = Order::factory()->withLines(1)->create([
            'shipping_method_identifier' => null,
        ]);

        OrderCreated::dispatch($order);

        $this->assertCount(0, $order->shipments()->get());
    }

    #[Test]
    public function it_skips_when_auto_create_is_disabled(): void
    {
        config()->set('shipping.auto_create_shipment', false);

        $order = Order::factory()->withLines(1)->create([
            'shipping_method_identifier' => 'standard',
        ]);

        OrderCreated::dispatch($order);

        $this->assertCount(0, $order->shipments()->get());
    }

    #[Test]
    public function it_throws_when_order_references_an_unknown_shipping_method_and_leaves_no_shipment_or_event(): void
    {
        Event::fake([ShipmentCreated::class]);

        $order = Order::factory()->withLines(1)->create([
            'shipping_method_identifier' => 'does_not_exist',
        ]);

        try {
            OrderCreated::dispatch($order);
            $this->fail('Expected RuntimeException for unknown shipping method.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertCount(0, $order->shipments()->get(),
            'Listener must not persist a Shipment when the method is unknown.');
        Event::assertNotDispatched(ShipmentCreated::class);
    }
}
