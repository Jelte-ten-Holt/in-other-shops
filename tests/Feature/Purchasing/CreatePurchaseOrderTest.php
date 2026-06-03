<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Purchasing;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Purchasing\Actions\CreatePurchaseOrder;
use InOtherShops\Purchasing\Enums\PurchaseOrderStatus;
use InOtherShops\Purchasing\Events\PurchaseOrderCreated;
use InOtherShops\Purchasing\Models\Supplier;
use InOtherShops\Tests\Stubs\TestPurchasable;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

final class CreatePurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function creates_a_draft_with_computed_totals(): void
    {
        $supplier = Supplier::factory()->create(['default_currency' => Currency::EUR]);

        $order = app(CreatePurchaseOrder::class)(
            supplier: $supplier,
            lines: [
                ['description' => 'Book A', 'sku' => 'BK-1', 'quantity_ordered' => 10, 'unit_cost' => 500],
                ['description' => 'Book B', 'quantity_ordered' => 2, 'unit_cost' => 1500],
            ],
            shippingCost: 800,
            customsCost: 200,
        );

        $this->assertSame(PurchaseOrderStatus::Draft, $order->status);
        $this->assertSame(Currency::EUR, $order->currency);
        $this->assertSame(8000, $order->subtotal);          // 10*500 + 2*1500
        $this->assertSame(9000, $order->total);             // subtotal + shipping + customs
        $this->assertNotEmpty($order->reference);
        $this->assertCount(2, $order->lines);
        $this->assertSame(5000, $order->lines->firstWhere('sku', 'BK-1')->line_cost);
    }

    #[Test]
    public function snapshots_description_and_sku_from_a_purchasable(): void
    {
        $supplier = Supplier::factory()->create();
        $book = TestPurchasable::factory()->create(['name' => 'The Hobbit', 'sku' => 'HOB-1']);

        $order = app(CreatePurchaseOrder::class)(
            supplier: $supplier,
            lines: [['purchasable' => $book, 'quantity_ordered' => 3, 'unit_cost' => 940]],
        );

        $line = $order->lines->first();
        $this->assertSame('The Hobbit', $line->description);
        $this->assertSame('HOB-1', $line->sku);
        $this->assertTrue($line->purchasable->is($book));
    }

    #[Test]
    public function defaults_currency_from_the_supplier(): void
    {
        $supplier = Supplier::factory()->create(['default_currency' => Currency::GBP]);

        $order = app(CreatePurchaseOrder::class)(
            supplier: $supplier,
            lines: [['description' => 'x', 'quantity_ordered' => 1, 'unit_cost' => 100]],
        );

        $this->assertSame(Currency::GBP, $order->currency);
    }

    #[Test]
    public function rejects_a_line_with_neither_description_nor_purchasable(): void
    {
        $supplier = Supplier::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        app(CreatePurchaseOrder::class)(
            supplier: $supplier,
            lines: [['quantity_ordered' => 1, 'unit_cost' => 100]],
        );
    }

    #[Test]
    public function dispatches_purchase_order_created(): void
    {
        Event::fake([PurchaseOrderCreated::class]);
        $supplier = Supplier::factory()->create();

        app(CreatePurchaseOrder::class)(
            supplier: $supplier,
            lines: [['description' => 'x', 'quantity_ordered' => 1, 'unit_cost' => 100]],
        );

        Event::assertDispatched(PurchaseOrderCreated::class);
    }
}
