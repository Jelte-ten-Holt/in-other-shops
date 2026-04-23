<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Shipping;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Shipping\Actions\CreateShipmentForOrder;
use InOtherShops\Shipping\Models\ShippingMethod;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class CreateShipmentForOrderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_a_shipment_with_snapshot_fields(): void
    {
        $order = Order::factory()->create();
        $method = ShippingMethod::factory()->create([
            'identifier' => 'express',
            'base_cost' => 999,
            'currency' => Currency::EUR->value,
        ]);

        $shipment = (new CreateShipmentForOrder)($order, $method);

        $this->assertSame('express', $shipment->method);
        $this->assertSame(999, $shipment->cost);
        $this->assertSame(Currency::EUR, $shipment->currency);
        $this->assertSame($order->id, $shipment->shippable_id);
    }
}
