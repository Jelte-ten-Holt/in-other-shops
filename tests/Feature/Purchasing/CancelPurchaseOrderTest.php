<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Purchasing;

use InOtherShops\Purchasing\Actions\CancelPurchaseOrder;
use InOtherShops\Purchasing\Actions\CreatePurchaseOrder;
use InOtherShops\Purchasing\Actions\PlacePurchaseOrder;
use InOtherShops\Purchasing\Actions\ReceiveItems;
use InOtherShops\Purchasing\Enums\PurchaseOrderStatus;
use InOtherShops\Purchasing\Exceptions\InvalidPurchaseOrderTransitionException;
use InOtherShops\Purchasing\Models\Supplier;
use InOtherShops\Tests\Stubs\TestPurchasable;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class CancelPurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function cancels_a_draft(): void
    {
        $supplier = Supplier::factory()->create();
        $order = app(CreatePurchaseOrder::class)(
            supplier: $supplier,
            lines: [['description' => 'x', 'quantity_ordered' => 1, 'unit_cost' => 100]],
        );

        $order = app(CancelPurchaseOrder::class)($order);

        $this->assertSame(PurchaseOrderStatus::Cancelled, $order->status);
    }

    #[Test]
    public function cancelling_does_not_reverse_already_received_stock(): void
    {
        $supplier = Supplier::factory()->create();
        $book = TestPurchasable::factory()->create();
        $order = app(CreatePurchaseOrder::class)(
            supplier: $supplier,
            lines: [['purchasable' => $book, 'quantity_ordered' => 10, 'unit_cost' => 500]],
        );
        app(PlacePurchaseOrder::class)($order);
        $lineId = $order->lines()->first()->id;
        app(ReceiveItems::class)($order->refresh(), [$lineId => 4]);

        $order = app(CancelPurchaseOrder::class)($order->refresh());

        $this->assertSame(PurchaseOrderStatus::Cancelled, $order->status);
        $this->assertSame(4, $book->fresh()->stockLevel());
    }

    #[Test]
    public function cannot_cancel_a_fully_received_order(): void
    {
        $supplier = Supplier::factory()->create();
        $book = TestPurchasable::factory()->create();
        $order = app(CreatePurchaseOrder::class)(
            supplier: $supplier,
            lines: [['purchasable' => $book, 'quantity_ordered' => 5, 'unit_cost' => 100]],
        );
        app(PlacePurchaseOrder::class)($order);
        $lineId = $order->lines()->first()->id;
        app(ReceiveItems::class)($order->refresh(), [$lineId => 5]);

        $this->expectException(InvalidPurchaseOrderTransitionException::class);

        app(CancelPurchaseOrder::class)($order->refresh());
    }
}
