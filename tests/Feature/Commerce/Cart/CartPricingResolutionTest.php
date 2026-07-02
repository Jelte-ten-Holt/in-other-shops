<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Cart;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use InOtherShops\Commerce\Cart\Http\Resources\CartItemResource;
use InOtherShops\Commerce\Cart\Http\Resources\CartResource;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Commerce\Cart\Models\CartItem;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Tests\Stubs\TestCartable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * T-B2 — the cart's currency and a line's effective unit price live on the
 * models, so the HTTP resources and the add-to-cart step can't compute them
 * differently. The load-bearing invariant: a line's `line_total` and the
 * cart `subtotal` derive the price the same way, so they always agree.
 */
final class CartPricingResolutionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function effective_unit_price_prefers_the_snapshot_over_the_live_cartable_price(): void
    {
        $cartable = TestCartable::factory()->create(['unit_price' => 1500]);
        $item = $this->itemFor($cartable, snapshot: 999);

        // Snapshot wins even though the live cartable price is different.
        $this->assertSame(999, $item->effectiveUnitPrice(Currency::EUR));
    }

    #[Test]
    public function effective_unit_price_falls_back_to_the_live_cartable_price_without_a_snapshot(): void
    {
        $cartable = TestCartable::factory()->create(['unit_price' => 1500]);
        $item = $this->itemFor($cartable, snapshot: null);

        $this->assertSame(1500, $item->effectiveUnitPrice(Currency::EUR));
    }

    #[Test]
    public function effective_unit_price_is_null_when_neither_snapshot_nor_live_price_exists(): void
    {
        $cartable = TestCartable::factory()->create(['unit_price' => null]);
        $item = $this->itemFor($cartable, snapshot: null);

        $this->assertNull($item->effectiveUnitPrice(Currency::EUR));
    }

    #[Test]
    public function cart_effective_currency_uses_its_own_currency_then_the_shipped_default(): void
    {
        $stamped = Cart::factory()->create(['currency' => Currency::GBP]);
        $this->assertSame(Currency::GBP, $stamped->effectiveCurrency());

        // A cart with no stamped currency (the persisted column is NOT NULL, so
        // this is the in-memory/pre-persist guard) falls back to the shipped
        // config default (commerce.cart.api.default_currency = EUR), untouched.
        $this->assertSame(Currency::EUR, (new Cart)->effectiveCurrency());
    }

    #[Test]
    public function cart_item_effective_currency_prefers_its_own_then_the_carts(): void
    {
        $gbpCart = Cart::factory()->create(['currency' => Currency::GBP]);

        // Distinct cartables — a cart may hold each cartable only once.
        $cartableA = TestCartable::factory()->create(['unit_price' => 100]);
        $cartableB = TestCartable::factory()->create(['unit_price' => 100]);

        $inheritsCart = $this->itemFor($cartableA, snapshot: 100, cart: $gbpCart, currency: null);
        $this->assertSame(Currency::GBP, $inheritsCart->effectiveCurrency());

        $ownCurrency = $this->itemFor($cartableB, snapshot: 100, cart: $gbpCart, currency: Currency::USD);
        $this->assertSame(Currency::USD, $ownCurrency->effectiveCurrency());
    }

    #[Test]
    public function cart_subtotal_equals_the_sum_of_line_totals_for_a_mixed_cart(): void
    {
        // A cart mixing a snapshot-priced line and a live-priced line — the
        // exact case where a divergent fallback rule would make the sum of the
        // rendered line_totals disagree with the rendered subtotal.
        $cart = Cart::factory()->create(['currency' => Currency::EUR]);

        $snapshotCartable = TestCartable::factory()->create(['unit_price' => 1500]);
        $this->itemFor($snapshotCartable, snapshot: 999, cart: $cart, quantity: 2);   // 1998

        $liveCartable = TestCartable::factory()->create(['unit_price' => 1500]);
        $this->itemFor($liveCartable, snapshot: null, cart: $cart, quantity: 3);       // 4500

        $cart->load('items.cartable');
        $request = Request::create('/');

        $subtotal = (new CartResource($cart))->toArray($request)['subtotal']['amount'];

        $lineSum = $cart->items->sum(
            fn (CartItem $item): int => (new CartItemResource($item))->toArray($request)['line_total']['amount'] ?? 0,
        );

        $this->assertSame(6498, $subtotal);
        $this->assertSame($subtotal, $lineSum, 'Sum of line_totals must equal the cart subtotal.');
    }

    private function itemFor(
        TestCartable $cartable,
        ?int $snapshot,
        ?Cart $cart = null,
        int $quantity = 1,
        ?Currency $currency = null,
    ): CartItem {
        $cart ??= Cart::factory()->create(['currency' => Currency::EUR]);

        /** @var CartItem */
        return $cart->items()->create([
            'cartable_type' => $cartable->getMorphClass(),
            'cartable_id' => $cartable->getKey(),
            'quantity' => $quantity,
            'unit_price' => $snapshot,
            'currency' => $currency,
        ]);
    }
}
