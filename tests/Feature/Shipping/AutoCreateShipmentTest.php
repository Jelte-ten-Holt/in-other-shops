<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Shipping;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Commerce\Order\Events\OrderCreated;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Shipping\Enums\ShipmentStatus;
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
        $this->assertSame(ShipmentStatus::Pending, $shipments->first()->status);
        $this->assertSame('standard', $shipments->first()->method);
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
    public function it_throws_when_order_references_an_unknown_shipping_method(): void
    {
        $order = Order::factory()->withLines(1)->create([
            'shipping_method_identifier' => 'does_not_exist',
        ]);

        $this->expectException(RuntimeException::class);

        OrderCreated::dispatch($order);
    }
}
