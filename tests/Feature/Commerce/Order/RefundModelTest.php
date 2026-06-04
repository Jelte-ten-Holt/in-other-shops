<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Order;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Commerce\Order\DTOs\RefundActor;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Enums\RefundActorSource;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Commerce\Order\Models\Refund;
use InOtherShops\Pricing\DTOs\TaxBreakdownLine;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Refund model + the order-level derived refund state (no OrderStatus case).
 */
final class RefundModelTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_order_exposes_its_refunds_and_refunded_total(): void
    {
        $order = Order::factory()->create(['total' => 1000]);

        Refund::factory()->create(['order_id' => $order->id, 'amount' => 300]);
        Refund::factory()->create(['order_id' => $order->id, 'amount' => 200]);

        $this->assertCount(2, $order->refunds()->get());
        $this->assertSame(500, $order->fresh()->refundedTotal());
    }

    #[Test]
    public function refund_state_is_derived_from_the_refunds_not_the_order_status(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::Confirmed, 'total' => 1000]);

        $this->assertFalse($order->isRefunded());
        $this->assertFalse($order->isPartiallyRefunded());

        Refund::factory()->create(['order_id' => $order->id, 'amount' => 400]);
        $order = $order->fresh();
        $this->assertTrue($order->isPartiallyRefunded());
        $this->assertFalse($order->isRefunded());

        Refund::factory()->create(['order_id' => $order->id, 'amount' => 600]);
        $order = $order->fresh();
        $this->assertTrue($order->isRefunded());
        $this->assertFalse($order->isPartiallyRefunded());

        // The order status itself is untouched — refunded-ness is orthogonal.
        $this->assertSame(OrderStatus::Confirmed, $order->status);
    }

    #[Test]
    public function it_exposes_the_reversed_tax_summary_as_breakdown_lines(): void
    {
        $refund = Refund::factory()->create([
            'tax_summary' => [
                ['rate_bps' => 1900, 'taxable_base' => 843, 'tax' => 160],
                ['rate_bps' => 700, 'taxable_base' => 707, 'tax' => 50],
            ],
        ]);

        $summary = $refund->fresh()->taxSummary();

        $this->assertContainsOnlyInstancesOf(TaxBreakdownLine::class, $summary);
        $this->assertSame(160, $summary[0]->tax);
        $this->assertSame(700, $summary[1]->rateBps);
    }

    #[Test]
    public function it_records_the_actor_and_round_trips_it_as_a_value_object(): void
    {
        $adminRefund = Refund::factory()->create([
            'actor_source' => RefundActorSource::Admin,
            'actor_id' => '42',
            'actor_label' => 'Jelte',
        ]);

        $actor = $adminRefund->fresh()->actor();
        $this->assertInstanceOf(RefundActor::class, $actor);
        $this->assertSame(RefundActorSource::Admin, $actor->source);
        $this->assertSame('42', $actor->id);

        // A gateway-initiated refund records the sentinel, not a null hole.
        $gatewayRefund = Refund::factory()->byGateway()->create();
        $this->assertSame(RefundActorSource::Gateway, $gatewayRefund->fresh()->actor()->source);
        $this->assertNull($gatewayRefund->actor()->id);
    }
}
