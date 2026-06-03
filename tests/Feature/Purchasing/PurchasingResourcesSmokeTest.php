<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Purchasing;

use InOtherShops\Purchasing\Filament\Resources\PurchaseOrderResource;
use InOtherShops\Purchasing\Filament\Resources\SupplierResource;
use InOtherShops\Purchasing\Models\PurchaseOrder;
use InOtherShops\Purchasing\Models\Supplier;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The package has no Filament render-test harness, so this is a parse/autoload
 * smoke check: it forces the Resource + Page classes to load (catching syntax
 * errors and bad imports) and asserts their model + page wiring. It does NOT
 * exercise the rendered forms — those need live verification in the panel.
 */
final class PurchasingResourcesSmokeTest extends TestCase
{
    #[Test]
    public function supplier_resource_wires_to_its_model_and_pages(): void
    {
        $this->assertSame(Supplier::class, SupplierResource::getModel());
        $this->assertArrayHasKey('index', SupplierResource::getPages());
        $this->assertArrayHasKey('create', SupplierResource::getPages());
        $this->assertArrayHasKey('edit', SupplierResource::getPages());
    }

    #[Test]
    public function purchase_order_resource_wires_to_its_model_and_pages(): void
    {
        $this->assertSame(PurchaseOrder::class, PurchaseOrderResource::getModel());
        $this->assertArrayHasKey('index', PurchaseOrderResource::getPages());
        $this->assertArrayHasKey('create', PurchaseOrderResource::getPages());
        $this->assertArrayHasKey('edit', PurchaseOrderResource::getPages());
    }
}
