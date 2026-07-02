<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Shipping;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Shipping\Actions\CreateShipment;
use InOtherShops\Shipping\ShippingConfig;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * T-B4 (decision D4): shipment history must survive order-line deletion.
 * The FK is restrictOnDelete — deleting a line that has shipment items is
 * blocked at the database, not silently cascaded into vanished history.
 */
final class ShipmentItemFkRestrictTest extends TestCase
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
    public function deleting_an_order_line_with_shipment_items_is_blocked(): void
    {
        $order = Order::factory()->withLines(1)->create();
        (new CreateShipment)($order, ShippingConfig::method('express'));

        $line = $order->lines()->firstOrFail();

        $this->expectException(QueryException::class);

        $line->delete();
    }

    #[Test]
    public function deleting_the_shipment_first_releases_the_line(): void
    {
        $order = Order::factory()->withLines(1)->create();
        $shipment = (new CreateShipment)($order, ShippingConfig::method('express'));

        // Cascade shipment -> shipment_items still applies; with the items
        // gone, the line is deletable. This is the intended escape hatch.
        $shipment->delete();

        $line = $order->lines()->firstOrFail();
        $line->delete();

        $this->assertDatabaseMissing('order_lines', ['id' => $line->id]);
    }
}
