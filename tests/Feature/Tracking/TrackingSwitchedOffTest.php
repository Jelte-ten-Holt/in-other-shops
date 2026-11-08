<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Tracking;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InOtherShops\Commerce\Cart\FlowChains\AddToCartPayload;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Tests\Stubs\StubCheckoutPayload;
use InOtherShops\Tests\Stubs\TestCartable;
use InOtherShops\Tests\TestCase;
use InOtherShops\Tracking\FlowChains\Steps\RecordCartItemAttribution;
use InOtherShops\Tracking\FlowChains\Steps\SnapshotCartItemAttributions;
use PHPUnit\Framework\Attributes\Test;

/**
 * With `tracking.enabled = false` the domain loads no migrations, so on a fresh
 * install its tables never exist. A consumer's published chain may still list
 * the two steps; they must write nothing and touch no table (PR 7).
 */
final class TrackingSwitchedOffTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('tracking.enabled', false);
    }

    #[Test]
    public function the_attribution_tables_are_never_created(): void
    {
        $this->assertFalse(Schema::hasTable('cart_item_attributions'));
        $this->assertFalse(Schema::hasTable('order_line_attributions'));
    }

    #[Test]
    public function both_steps_write_nothing_and_do_not_throw(): void
    {
        $source = TestCartable::factory()->create();
        $cartable = TestCartable::factory()->create(['unit_price' => 1000]);
        $cart = Cart::factory()->create();
        $cartItem = $cart->items()->create([
            'cartable_type' => $cartable->getMorphClass(),
            'cartable_id' => $cartable->getKey(),
            'quantity' => 1,
            'unit_price' => 1000,
            'currency' => Currency::EUR,
        ]);
        $payload = new AddToCartPayload(cart: $cart, cartable: $cartable, quantity: 1, metadata: [
            'source' => ['type' => 'test_cartable', 'id' => (int) $source->getKey()],
        ]);
        $payload->cartItem = $cartItem;

        app(RecordCartItemAttribution::class)->handle($payload);

        $order = Order::factory()->create();
        $order->lines()->create([
            'orderable_type' => $cartable->getMorphClass(),
            'orderable_id' => $cartable->getKey(),
            'description' => 'A line',
            'currency' => Currency::EUR,
            'unit_price' => 1000,
            'quantity' => 1,
            'line_total' => 1000,
            'tax_amount' => 0,
        ]);

        app(SnapshotCartItemAttributions::class)->handle(new StubCheckoutPayload($cart->load('items'), $order->load('lines')));

        $this->assertFalse(Schema::hasTable('cart_item_attributions'), 'and nothing created it either');
    }
}
