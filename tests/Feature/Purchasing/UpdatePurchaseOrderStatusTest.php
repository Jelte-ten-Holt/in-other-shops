<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Purchasing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Purchasing\Actions\UpdatePurchaseOrderStatus;
use InOtherShops\Purchasing\Enums\PurchaseOrderStatus;
use InOtherShops\Purchasing\Exceptions\InvalidPurchaseOrderTransitionException;
use InOtherShops\Purchasing\Models\PurchaseOrder;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * T-B3: every purchase-order status write routes through this action so the
 * state machine is authoritative — including ReceiveItems, which previously
 * wrote status with a bare update() guarded only by the coarse isReceivable().
 */
final class UpdatePurchaseOrderStatusTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_valid_transition_updates_the_status_and_merges_attributes(): void
    {
        $order = PurchaseOrder::factory()->create(['status' => PurchaseOrderStatus::Draft]);

        app(UpdatePurchaseOrderStatus::class)($order, PurchaseOrderStatus::Ordered, ['ordered_at' => now()]);

        $order->refresh();
        $this->assertSame(PurchaseOrderStatus::Ordered, $order->status);
        $this->assertNotNull($order->ordered_at);
    }

    #[Test]
    public function an_illegal_transition_throws_and_leaves_the_status_untouched(): void
    {
        $order = PurchaseOrder::factory()->create(['status' => PurchaseOrderStatus::Received]);

        try {
            app(UpdatePurchaseOrderStatus::class)($order, PurchaseOrderStatus::PartiallyReceived);
            $this->fail('Expected InvalidPurchaseOrderTransitionException.');
        } catch (InvalidPurchaseOrderTransitionException) {
            // expected
        }

        $this->assertSame(PurchaseOrderStatus::Received, $order->refresh()->status);
    }

    #[Test]
    public function a_terminal_status_cannot_be_reopened(): void
    {
        $order = PurchaseOrder::factory()->create(['status' => PurchaseOrderStatus::Cancelled]);

        $this->expectException(InvalidPurchaseOrderTransitionException::class);

        app(UpdatePurchaseOrderStatus::class)($order, PurchaseOrderStatus::Ordered);
    }
}
