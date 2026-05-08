<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Shipping;

use InOtherShops\Shipping\Actions\ResolveShippingZoneForCountry;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ResolveShippingZoneTest extends TestCase
{
    #[Test]
    public function it_resolves_a_zone_for_a_country_in_a_zone(): void
    {
        config()->set('shipping.zones', [
            'de' => ['name' => 'Germany', 'currency' => 'EUR', 'countries' => ['DE']],
            'eu' => ['name' => 'EU', 'currency' => 'EUR', 'countries' => ['NL', 'BE']],
        ]);

        $zone = (new ResolveShippingZoneForCountry)('NL');

        $this->assertNotNull($zone);
        $this->assertSame('eu', $zone->identifier);
    }

    #[Test]
    public function it_returns_null_for_a_country_not_in_any_zone(): void
    {
        config()->set('shipping.zones', [
            'de' => ['currency' => 'EUR', 'countries' => ['DE']],
        ]);

        $this->assertNull((new ResolveShippingZoneForCountry)('US'));
    }

    #[Test]
    public function it_handles_lowercase_country_codes(): void
    {
        config()->set('shipping.zones', [
            'de' => ['currency' => 'EUR', 'countries' => ['DE']],
        ]);

        $zone = (new ResolveShippingZoneForCountry)('de');

        $this->assertNotNull($zone);
        $this->assertSame('de', $zone->identifier);
    }
}
