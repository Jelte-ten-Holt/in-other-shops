<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Shipping;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Shipping\Actions\ListAvailableShippingMethods;
use InOtherShops\Shipping\Actions\ResolveShippingZoneForCountry;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Pin the *shipped* `config/shipping.php` defaults. Every behavioural test
 * in this suite overrides `shipping.zones` and `shipping.methods` so the
 * shipped empty defaults are never actually exercised — that means a
 * regression to those defaults (e.g. an accidental hard-coded "standard"
 * method, or a typo'd auto-create flag) would not surface in the suite.
 */
final class ShippedDefaultConfigTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function shipped_zones_are_empty(): void
    {
        $this->assertSame([], config('shipping.zones'),
            'Consumers must declare their own zones; the package must not ship presumptions.');
    }

    #[Test]
    public function shipped_methods_are_empty(): void
    {
        $this->assertSame([], config('shipping.methods'));
    }

    #[Test]
    public function shipped_auto_create_shipment_is_enabled(): void
    {
        // Documented behaviour: package ships a Pending shipment per order
        // unless the consumer opts out. AutoCreateShipmentTest covers the
        // opt-out branch; this pins the shipped on-by-default value so a
        // regression that flipped the default to false would surface here.
        $this->assertTrue(config('shipping.auto_create_shipment'));
    }

    #[Test]
    public function list_available_shipping_methods_returns_empty_against_shipped_defaults(): void
    {
        // The no-op smoke: with no methods configured, the action must not
        // throw and must not invent any.
        $methods = (new ListAvailableShippingMethods)();

        $this->assertSame([], $methods);
    }

    #[Test]
    public function resolve_shipping_zone_returns_null_for_any_country_against_shipped_defaults(): void
    {
        // No zones means no country resolves. The action must not throw —
        // a regression that mis-handled an empty zones array would surface.
        $zone = (new ResolveShippingZoneForCountry)('DE');

        $this->assertNull($zone);
    }
}
