<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Order;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Commerce\Commerce;
use InOtherShops\Commerce\Customer\Models\Customer;
use InOtherShops\Commerce\Order\Actions\ResolvePreOrderAudience;
use InOtherShops\Commerce\Order\DTOs\PreOrderRecipient;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Shipping\Enums\ShipmentStatus;
use InOtherShops\Shipping\Models\Shipment;
use InOtherShops\Shipping\Models\ShipmentItem;
use InOtherShops\Tests\Stubs\TestCartable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * ResolvePreOrderAudience answers "who pre-ordered this purchasable on a live
 * order" — guests included, deduped by email, and deliberately blind to
 * shipment state (the architectural decision this suite pins).
 */
final class ResolvePreOrderAudienceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_returns_a_normalized_recipient_for_a_customer_who_pre_ordered_the_product(): void
    {
        $product = TestCartable::factory()->create();
        $customer = $this->customer('Alice@Example.com', 'Alice');
        $order = $this->orderFor($customer, OrderStatus::Confirmed, email: 'Alice@Example.com', locale: 'de');
        $this->preOrderLine($order, $product);

        $audience = (new ResolvePreOrderAudience)($product);

        $this->assertCount(1, $audience);
        $recipient = $audience->first();
        $this->assertInstanceOf(PreOrderRecipient::class, $recipient);
        $this->assertSame('alice@example.com', $recipient->email);
        $this->assertSame('Alice', $recipient->name);
        $this->assertSame('de', $recipient->locale);
        $this->assertSame($customer->id, $recipient->customerId);
    }

    #[Test]
    public function it_includes_guest_pre_orderers_via_the_order_email(): void
    {
        $product = TestCartable::factory()->create();
        $order = $this->guestOrder('guest@example.com', OrderStatus::Confirmed);
        $this->preOrderLine($order, $product);

        $audience = (new ResolvePreOrderAudience)($product);

        $this->assertCount(1, $audience);
        $this->assertSame('guest@example.com', $audience->first()->email);
        $this->assertNull($audience->first()->customerId);
    }

    #[Test]
    public function it_ignores_non_pre_order_lines(): void
    {
        $product = TestCartable::factory()->create();
        $order = $this->guestOrder('buyer@example.com', OrderStatus::Confirmed);
        $this->line($order, $product, isPreOrder: false);

        $this->assertCount(0, (new ResolvePreOrderAudience)($product));
    }

    #[Test]
    public function it_ignores_pre_order_lines_on_cancelled_orders(): void
    {
        $product = TestCartable::factory()->create();
        $order = $this->guestOrder('buyer@example.com', OrderStatus::Cancelled);
        $this->preOrderLine($order, $product);

        $this->assertCount(0, (new ResolvePreOrderAudience)($product));
    }

    #[Test]
    public function it_ignores_pre_orders_of_a_different_product(): void
    {
        $product = TestCartable::factory()->create();
        $other = TestCartable::factory()->create();
        $order = $this->guestOrder('buyer@example.com', OrderStatus::Confirmed);
        $this->preOrderLine($order, $other);

        $this->assertCount(0, (new ResolvePreOrderAudience)($product));
    }

    #[Test]
    public function it_deduplicates_a_customer_with_several_pre_order_lines_and_keeps_the_customer_id(): void
    {
        $product = TestCartable::factory()->create();
        $customer = $this->customer('repeat@example.com', 'Repeat Buyer');

        $first = $this->orderFor($customer, OrderStatus::Confirmed, email: 'repeat@example.com');
        $this->preOrderLine($first, $product);
        $this->preOrderLine($first, $product);
        $second = $this->orderFor($customer, OrderStatus::Confirmed, email: 'repeat@example.com');
        $this->preOrderLine($second, $product);

        $audience = (new ResolvePreOrderAudience)($product);

        $this->assertCount(1, $audience);
        $this->assertSame('repeat@example.com', $audience->first()->email);
        $this->assertSame($customer->id, $audience->first()->customerId);
    }

    #[Test]
    public function it_is_shipment_agnostic_a_recipient_remains_even_after_their_shipment_is_delivered(): void
    {
        // The decision this suite exists to pin: the audience is about who
        // pre-ordered, not who is still waiting. A delivered shipment must not
        // drop the recipient — that filtering, if ever wanted, is a consumer
        // concern, not this Commerce action's.
        $product = TestCartable::factory()->create();
        $order = $this->guestOrder('delivered@example.com', OrderStatus::Confirmed);
        $line = $this->preOrderLine($order, $product);

        $shipment = Shipment::factory()->for($order, 'shippable')->create([
            'status' => ShipmentStatus::Delivered,
            'method' => 'standard',
        ]);
        ShipmentItem::factory()->create([
            'shipment_id' => $shipment->id,
            'order_line_id' => $line->id,
        ]);

        $audience = (new ResolvePreOrderAudience)($product);

        $this->assertCount(1, $audience);
        $this->assertSame('delivered@example.com', $audience->first()->email);
    }

    private function customer(string $email, string $name): Customer
    {
        return Commerce::customer()::factory()->create(['email' => $email, 'name' => $name]);
    }

    private function orderFor(Customer $customer, OrderStatus $status, string $email, ?string $locale = null): Order
    {
        return Commerce::order()::factory()->forCustomer($customer)->create([
            'status' => $status,
            'email' => $email,
            'locale' => $locale,
        ]);
    }

    private function guestOrder(string $email, OrderStatus $status): Order
    {
        return Commerce::order()::factory()->create([
            'customer_id' => null,
            'email' => $email,
            'status' => $status,
        ]);
    }

    private function preOrderLine(Order $order, TestCartable $product): \InOtherShops\Commerce\Order\Models\OrderLine
    {
        return $this->line($order, $product, isPreOrder: true);
    }

    private function line(Order $order, TestCartable $product, bool $isPreOrder): \InOtherShops\Commerce\Order\Models\OrderLine
    {
        return Commerce::orderLine()::factory()->create([
            'order_id' => $order->id,
            'is_pre_order' => $isPreOrder,
            'orderable_type' => $product->getMorphClass(),
            'orderable_id' => $product->id,
        ]);
    }
}
