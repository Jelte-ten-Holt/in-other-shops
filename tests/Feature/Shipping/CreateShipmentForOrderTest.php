<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Shipping;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Shipping\Actions\CreateShipmentForOrder;
use InOtherShops\Shipping\ShippingConfig;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class CreateShipmentForOrderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_a_shipment_with_snapshot_fields(): void
    {
        config()->set('shipping.zones', [
            'de' => ['name' => 'Germany', 'currency' => 'EUR', 'countries' => ['DE']],
        ]);
        config()->set('shipping.methods', [
            'express' => ['name' => 'Express', 'rates' => ['de' => 999]],
        ]);

        $order = Order::factory()->create();
        $method = ShippingConfig::method('express');
        $zone = ShippingConfig::zone('de');

        $shipment = (new CreateShipmentForOrder)($order, $method, $zone, 999);

        $this->assertSame('express', $shipment->method);
        $this->assertSame(999, $shipment->cost);
        $this->assertSame(Currency::EUR, $shipment->currency);
        $this->assertSame($order->id, $shipment->shippable_id);
    }

    #[Test]
    public function it_records_the_caller_provided_cost_after_free_shipping(): void
    {
        config()->set('shipping.zones', [
            'de' => ['name' => 'Germany', 'currency' => 'EUR', 'countries' => ['DE'], 'free_shipping_threshold' => 5000],
        ]);
        config()->set('shipping.methods', [
            'standard' => ['name' => 'Standard', 'rates' => ['de' => 595]],
        ]);

        $order = Order::factory()->create();
        $method = ShippingConfig::method('standard');
        $zone = ShippingConfig::zone('de');

        $shipment = (new CreateShipmentForOrder)($order, $method, $zone, 0);

        $this->assertSame('standard', $shipment->method);
        $this->assertSame(0, $shipment->cost);
    }
}
