<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Purchasing;

use InOtherShops\Inventory\Enums\StockMovementReason;
use InOtherShops\Inventory\Inventory;
use InOtherShops\Purchasing\Actions\CreatePurchaseOrder;
use InOtherShops\Purchasing\Actions\PlacePurchaseOrder;
use InOtherShops\Purchasing\Actions\ReceiveItems;
use InOtherShops\Purchasing\Enums\PurchaseOrderStatus;
use InOtherShops\Purchasing\Events\ItemsReceived;
use InOtherShops\Purchasing\Exceptions\InvalidPurchaseOrderTransitionException;
use InOtherShops\Purchasing\Exceptions\ReceiveExceedsOutstandingException;
use InOtherShops\Purchasing\Models\PurchaseOrder;
use InOtherShops\Purchasing\Models\Supplier;
use InOtherShops\Tests\Stubs\TestPurchasable;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

final class ReceiveItemsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: PurchaseOrder, 1: TestPurchasable, 2: int}
     */
    private function orderedPurchaseOrder(int $quantityOrdered = 10): array
    {
        $supplier = Supplier::factory()->create();
        $book = TestPurchasable::factory()->create();

        $order = app(CreatePurchaseOrder::class)(
            supplier: $supplier,
            lines: [['purchasable' => $book, 'quantity_ordered' => $quantityOrdered, 'unit_cost' => 500]],
        );
        app(PlacePurchaseOrder::class)($order);

        return [$order->refresh(), $book, $order->lines()->first()->id];
    }

    #[Test]
    public function partial_receipt_moves_stock_and_marks_partially_received(): void
    {
        [$order, $book, $lineId] = $this->orderedPurchaseOrder(10);

        $order = app(ReceiveItems::class)($order, [$lineId => 4]);

        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $order->status);
        $this->assertSame(4, $order->lines()->find($lineId)->quantity_received);
        $this->assertSame(4, $book->fresh()->stockLevel());
    }

    #[Test]
    public function receipt_drops_a_received_movement_referencing_the_line(): void
    {
        [$order, $book, $lineId] = $this->orderedPurchaseOrder(10);

        app(ReceiveItems::class)($order, [$lineId => 6]);

        $movements = $book->stockMovements()->get();
        $this->assertCount(1, $movements);
        $this->assertSame(StockMovementReason::Received, $movements->first()->reason);
        $this->assertSame(6, $movements->first()->quantity);
        $this->assertSame($lineId, (int) $movements->first()->reference_id);
        $this->assertSame('purchase_order_line', $movements->first()->reference_type);
    }

    #[Test]
    public function full_receipt_marks_received(): void
    {
        [$order, $book, $lineId] = $this->orderedPurchaseOrder(10);

        $order = app(ReceiveItems::class)($order, [$lineId => 10]);

        $this->assertSame(PurchaseOrderStatus::Received, $order->status);
        $this->assertSame(10, $book->fresh()->stockLevel());
    }

    #[Test]
    public function receiving_in_stages_accumulates_to_received(): void
    {
        [$order, $book, $lineId] = $this->orderedPurchaseOrder(10);

        $order = app(ReceiveItems::class)($order, [$lineId => 4]);
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $order->status);

        $order = app(ReceiveItems::class)($order, [$lineId => 6]);
        $this->assertSame(PurchaseOrderStatus::Received, $order->status);
        $this->assertSame(10, $book->fresh()->stockLevel());
    }

    #[Test]
    public function over_receiving_a_line_is_rejected(): void
    {
        [$order, , $lineId] = $this->orderedPurchaseOrder(10);

        $this->expectException(ReceiveExceedsOutstandingException::class);

        app(ReceiveItems::class)($order, [$lineId => 11]);
    }

    #[Test]
    public function a_single_over_receive_rolls_back_the_whole_receipt(): void
    {
        $supplier = Supplier::factory()->create();
        $a = TestPurchasable::factory()->create();
        $b = TestPurchasable::factory()->create();

        $order = app(CreatePurchaseOrder::class)(
            supplier: $supplier,
            lines: [
                ['purchasable' => $a, 'quantity_ordered' => 5, 'unit_cost' => 100],
                ['purchasable' => $b, 'quantity_ordered' => 5, 'unit_cost' => 100],
            ],
        );
        app(PlacePurchaseOrder::class)($order);
        $lines = $order->lines()->get();
        $lineA = $lines[0]->id;
        $lineB = $lines[1]->id;

        try {
            app(ReceiveItems::class)($order->refresh(), [$lineA => 3, $lineB => 99]);
            $this->fail('Expected ReceiveExceedsOutstandingException.');
        } catch (ReceiveExceedsOutstandingException) {
            // expected
        }

        $this->assertSame(0, $a->fresh()->stockLevel());
        $this->assertSame(0, $order->lines()->find($lineA)->quantity_received);
        $this->assertSame(PurchaseOrderStatus::Ordered, $order->fresh()->status);
    }

    #[Test]
    public function cannot_receive_against_a_draft(): void
    {
        $supplier = Supplier::factory()->create();
        $book = TestPurchasable::factory()->create();
        $order = app(CreatePurchaseOrder::class)(
            supplier: $supplier,
            lines: [['purchasable' => $book, 'quantity_ordered' => 5, 'unit_cost' => 100]],
        );
        $lineId = $order->lines()->first()->id;

        $this->expectException(InvalidPurchaseOrderTransitionException::class);

        app(ReceiveItems::class)($order, [$lineId => 1]);
    }

    #[Test]
    public function a_line_without_a_purchasable_receives_without_moving_stock(): void
    {
        $supplier = Supplier::factory()->create();
        $order = app(CreatePurchaseOrder::class)(
            supplier: $supplier,
            lines: [['description' => 'Freight handling', 'quantity_ordered' => 1, 'unit_cost' => 1000]],
        );
        app(PlacePurchaseOrder::class)($order);
        $lineId = $order->lines()->first()->id;

        $order = app(ReceiveItems::class)($order, [$lineId => 1]);

        $this->assertSame(PurchaseOrderStatus::Received, $order->status);
        $this->assertSame(1, $order->lines()->find($lineId)->quantity_received);
        $this->assertSame(0, Inventory::stockMovement()::query()->count());
    }

    #[Test]
    public function dispatches_items_received(): void
    {
        [$order, , $lineId] = $this->orderedPurchaseOrder(10);
        Event::fake([ItemsReceived::class]);

        app(ReceiveItems::class)($order, [$lineId => 2]);

        Event::assertDispatched(ItemsReceived::class);
    }
}
