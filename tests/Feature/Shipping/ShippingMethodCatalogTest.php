<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Shipping;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Shipping\Actions\CalculateShippingCost;
use InOtherShops\Shipping\Actions\ListAvailableShippingMethods;
use InOtherShops\Shipping\Models\ShippingMethod;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ShippingMethodCatalogTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_lists_only_active_methods_in_sort_order(): void
    {
        ShippingMethod::factory()->create(['identifier' => 'express', 'name' => 'Express', 'sort_order' => 10]);
        ShippingMethod::factory()->create(['identifier' => 'standard', 'name' => 'Standard', 'sort_order' => 0]);
        ShippingMethod::factory()->inactive()->create(['identifier' => 'retired', 'name' => 'Retired', 'sort_order' => 5]);

        $list = (new ListAvailableShippingMethods)();

        $this->assertCount(2, $list);
        $this->assertSame(['standard', 'express'], $list->pluck('identifier')->all());
    }

    #[Test]
    public function calculate_returns_method_base_cost(): void
    {
        $method = ShippingMethod::factory()->create(['base_cost' => 799]);

        $cost = (new CalculateShippingCost)($method);

        $this->assertSame(799, $cost);
    }
}
