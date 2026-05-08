<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Shipping;

use InOtherShops\Shipping\Actions\ListAvailableShippingMethods;
use InOtherShops\Shipping\ShippingConfig;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ShippingMethodCatalogTest extends TestCase
{
    #[Test]
    public function it_lists_only_active_methods_in_sort_order(): void
    {
        config()->set('shipping.zones', [
            'de' => ['name' => 'Germany', 'currency' => 'EUR', 'countries' => ['DE']],
        ]);
        config()->set('shipping.methods', [
            'express' => ['name' => 'Express', 'sort_order' => 10, 'rates' => ['de' => 999]],
            'standard' => ['name' => 'Standard', 'sort_order' => 0, 'rates' => ['de' => 595]],
            'retired' => ['name' => 'Retired', 'sort_order' => 5, 'is_active' => false, 'rates' => ['de' => 700]],
        ]);

        $list = (new ListAvailableShippingMethods)();

        $this->assertCount(2, $list);
        $this->assertSame(['standard', 'express'], array_map(fn ($m) => $m->identifier, $list));
    }

    #[Test]
    public function it_filters_methods_by_zone_availability(): void
    {
        config()->set('shipping.zones', [
            'de' => ['name' => 'Germany', 'currency' => 'EUR', 'countries' => ['DE']],
            'eu' => ['name' => 'EU', 'currency' => 'EUR', 'countries' => ['NL']],
        ]);
        config()->set('shipping.methods', [
            'standard' => ['name' => 'Standard', 'sort_order' => 0, 'rates' => ['de' => 595, 'eu' => 1499]],
            'express' => ['name' => 'Express', 'sort_order' => 10, 'rates' => ['de' => 999]],
        ]);

        $deList = (new ListAvailableShippingMethods)(ShippingConfig::zone('de'));
        $euList = (new ListAvailableShippingMethods)(ShippingConfig::zone('eu'));

        $this->assertSame(['standard', 'express'], array_map(fn ($m) => $m->identifier, $deList));
        $this->assertSame(['standard'], array_map(fn ($m) => $m->identifier, $euList));
    }
}
