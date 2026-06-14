<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Purchasing;

use InOtherShops\Purchasing\Actions\CancelPurchaseOrder;
use InOtherShops\Purchasing\Actions\CreatePurchaseOrder;
use InOtherShops\Purchasing\Actions\PlacePurchaseOrder;
use InOtherShops\Purchasing\Actions\ReceiveItems;
use InOtherShops\Purchasing\Models\Supplier;
use InOtherShops\Tests\Stubs\TestPurchasable;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class IncomingQuantityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function incoming_sums_outstanding_across_open_orders_only(): void
    {
        $supplier = Supplier::factory()->create();
        $book = TestPurchasable::factory()->create();

        // Ordered, untouched → outstanding 10.
        $ordered = $this->order($supplier, $book, 10);
        app(PlacePurchaseOrder::class)($ordered);

        // Ordered then partially received 4 of 6 → outstanding 2.
        $partial = $this->order($supplier, $book, 6);
        app(PlacePurchaseOrder::class)($partial);
        app(ReceiveItems::class)($partial->refresh(), [$partial->lines()->first()->id => 4]);

        // Draft (never placed) → excluded.
        $this->order($supplier, $book, 5);

        // Fully received → excluded.
        $received = $this->order($supplier, $book, 3);
        app(PlacePurchaseOrder::class)($received);
        app(ReceiveItems::class)($received->refresh(), [$received->lines()->first()->id => 3]);

        // Cancelled → excluded.
        $cancelled = $this->order($supplier, $book, 7);
        app(PlacePurchaseOrder::class)($cancelled);
        app(CancelPurchaseOrder::class)($cancelled->refresh());

        $this->assertSame(12, $book->incomingQuantity());
        $this->assertCount(2, $book->incomingPurchaseLines()->get());
    }

    #[Test]
    public function incoming_is_scoped_to_the_purchasable(): void
    {
        $supplier = Supplier::factory()->create();
        $book = TestPurchasable::factory()->create();
        $other = TestPurchasable::factory()->create();

        app(PlacePurchaseOrder::class)($this->order($supplier, $book, 8));

        $this->assertSame(8, $book->incomingQuantity());
        $this->assertSame(0, $other->incomingQuantity());
    }

    #[Test]
    public function incoming_is_zero_with_no_purchase_orders(): void
    {
        $book = TestPurchasable::factory()->create();

        $this->assertSame(0, $book->incomingQuantity());
        $this->assertCount(0, $book->incomingPurchaseLines()->get());
    }

    private function order(Supplier $supplier, TestPurchasable $purchasable, int $quantity): \InOtherShops\Purchasing\Models\PurchaseOrder
    {
        return app(CreatePurchaseOrder::class)(
            supplier: $supplier,
            lines: [['purchasable' => $purchasable, 'quantity_ordered' => $quantity, 'unit_cost' => 500]],
        );
    }
}
