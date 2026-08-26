<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Tracking;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Commerce\Cart\Models\CartItem;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\FlowChain\Contracts\FlowPayload;
use InOtherShops\Tests\Stubs\StubCheckoutPayload;
use InOtherShops\Tests\Stubs\TestCartable;
use InOtherShops\Tests\TestCase;
use InOtherShops\Tracking\FlowChains\Steps\SnapshotCartItemAttributions;
use InOtherShops\Tracking\Models\CartItemAttribution;
use InOtherShops\Tracking\Models\OrderLineAttribution;
use PHPUnit\Framework\Attributes\Test;

/**
 * The step that makes attribution durable: carts are cleared once payment
 * lands, so anything not copied onto the order line at checkout is lost.
 *
 * Unlike RecordCartItemAttribution, this one runs on the money path inside the
 * checkout transaction — which is why the missing-contract case throws rather
 * than skipping. A checkout that silently drops attribution looks identical to
 * one that had none.
 */
final class SnapshotCartItemAttributionsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_copies_the_attribution_onto_the_matching_order_line(): void
    {
        $source = TestCartable::factory()->create();
        [$cart, $cartable] = $this->cartWithItem();
        $this->attribute($cart->items->first(), $source);

        $order = $this->orderWithLineFor($cartable);

        app(SnapshotCartItemAttributions::class)->handle(new StubCheckoutPayload($cart, $order));

        $row = OrderLineAttribution::query()->sole();
        $this->assertSame($order->lines->first()->id, (int) $row->order_line_id);
        $this->assertSame($source->getMorphClass(), $row->source_type);
        $this->assertSame((int) $source->getKey(), (int) $row->source_id);
        $this->assertNotNull($row->created_at);
    }

    #[Test]
    public function each_line_gets_its_own_source_across_a_multi_line_order(): void
    {
        // Matching is by orderable identity, not by row order — so build the
        // order lines in the REVERSE order of the cart items. A positional
        // implementation passes a same-order test and fails this one.
        $sourceA = TestCartable::factory()->create();
        $sourceB = TestCartable::factory()->create();

        $cart = Cart::factory()->create();
        $cartableA = $this->addItem($cart, 1000);
        $cartableB = $this->addItem($cart, 2000);
        $cart->load('items');

        $this->attribute($cart->items->firstWhere('cartable_id', $cartableA->getKey()), $sourceA);
        $this->attribute($cart->items->firstWhere('cartable_id', $cartableB->getKey()), $sourceB);

        $order = Order::factory()->create();
        $lineB = $this->addLine($order, $cartableB);
        $lineA = $this->addLine($order, $cartableA);
        $order->load('lines');

        app(SnapshotCartItemAttributions::class)->handle(new StubCheckoutPayload($cart, $order));

        $this->assertSame(2, OrderLineAttribution::query()->count());
        $this->assertSame(
            (int) $sourceA->getKey(),
            (int) OrderLineAttribution::query()->where('order_line_id', $lineA->id)->sole()->source_id,
        );
        $this->assertSame(
            (int) $sourceB->getKey(),
            (int) OrderLineAttribution::query()->where('order_line_id', $lineB->id)->sole()->source_id,
        );
    }

    #[Test]
    public function lines_without_an_attribution_are_skipped(): void
    {
        $source = TestCartable::factory()->create();

        $cart = Cart::factory()->create();
        $attributed = $this->addItem($cart, 1000);
        $unattributed = $this->addItem($cart, 2000);
        $cart->load('items');

        $this->attribute($cart->items->firstWhere('cartable_id', $attributed->getKey()), $source);

        $order = Order::factory()->create();
        $attributedLine = $this->addLine($order, $attributed);
        $this->addLine($order, $unattributed);
        $order->load('lines');

        app(SnapshotCartItemAttributions::class)->handle(new StubCheckoutPayload($cart, $order));

        $this->assertSame(1, OrderLineAttribution::query()->count());
        $this->assertSame($attributedLine->id, (int) OrderLineAttribution::query()->sole()->order_line_id);
    }

    #[Test]
    public function an_empty_attribution_table_leaves_checkout_untouched(): void
    {
        // The normal case for a shop that has just adopted the domain, and the
        // one that must never throw: no attributions, no snapshot, no error.
        [$cart, $cartable] = $this->cartWithItem();
        $order = $this->orderWithLineFor($cartable);

        app(SnapshotCartItemAttributions::class)->handle(new StubCheckoutPayload($cart, $order));

        $this->assertSame(0, OrderLineAttribution::query()->count());
    }

    #[Test]
    public function an_order_line_with_no_matching_cart_item_is_skipped(): void
    {
        $source = TestCartable::factory()->create();
        [$cart] = $this->cartWithItem();
        $this->attribute($cart->items->first(), $source);

        // A line for something that was never in this cart — no identity match.
        $order = $this->orderWithLineFor(TestCartable::factory()->create());

        app(SnapshotCartItemAttributions::class)->handle(new StubCheckoutPayload($cart, $order));

        $this->assertSame(0, OrderLineAttribution::query()->count());
    }

    #[Test]
    public function a_payload_without_an_order_yet_writes_nothing(): void
    {
        $source = TestCartable::factory()->create();
        [$cart] = $this->cartWithItem();
        $this->attribute($cart->items->first(), $source);

        app(SnapshotCartItemAttributions::class)->handle(new StubCheckoutPayload($cart, null));

        $this->assertSame(0, OrderLineAttribution::query()->count());
    }

    #[Test]
    public function a_payload_that_does_not_implement_the_contract_throws(): void
    {
        // Loud on purpose: this is a wiring error, and the alternative — a
        // silent skip — is indistinguishable from a checkout with no
        // attribution to snapshot.
        $payload = new class implements FlowPayload {};

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/HasCheckoutAttribution/');

        app(SnapshotCartItemAttributions::class)->handle($payload);
    }

    /** @return array{0: Cart, 1: TestCartable} */
    private function cartWithItem(): array
    {
        $cart = Cart::factory()->create();
        $cartable = $this->addItem($cart, 1000);
        $cart->load('items');

        return [$cart, $cartable];
    }

    private function addItem(Cart $cart, int $price): TestCartable
    {
        $cartable = TestCartable::factory()->create(['unit_price' => $price]);

        $cart->items()->create([
            'cartable_type' => $cartable->getMorphClass(),
            'cartable_id' => $cartable->getKey(),
            'quantity' => 1,
            'unit_price' => $price,
            'currency' => Currency::EUR,
        ]);

        return $cartable;
    }

    private function orderWithLineFor(TestCartable $cartable): Order
    {
        $order = Order::factory()->create();
        $this->addLine($order, $cartable);

        return $order->load('lines');
    }

    private function addLine(Order $order, TestCartable $cartable): mixed
    {
        return $order->lines()->create([
            'orderable_type' => $cartable->getMorphClass(),
            'orderable_id' => $cartable->getKey(),
            'description' => 'A line',
            'currency' => Currency::EUR,
            'unit_price' => 1000,
            'quantity' => 1,
            'line_total' => 1000,
            'tax_amount' => 0,
        ]);
    }

    private function attribute(CartItem $cartItem, TestCartable $source): void
    {
        CartItemAttribution::query()->create([
            'cart_item_id' => $cartItem->id,
            'source_type' => $source->getMorphClass(),
            'source_id' => $source->getKey(),
            'created_at' => now(),
        ]);
    }
}
