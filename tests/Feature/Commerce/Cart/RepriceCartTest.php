<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Cart;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Commerce\Cart\Actions\RepriceCart;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Commerce\Cart\Models\CartItem;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Tests\Stubs\TestCartable;
use InOtherShops\Tests\Stubs\TestVariantable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The freshness half of the price-source contract: cart lines snapshot
 * `unit_price` at add time; this action refreshes the snapshot to the live
 * resolved price on the documented cadence (render / voucher apply /
 * submit-with-bounce) so quote, lines, threshold, and charge agree.
 */
final class RepriceCartTest extends TestCase
{
    use RefreshDatabase;

    private RepriceCart $reprice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reprice = $this->app->make(RepriceCart::class);
    }

    #[Test]
    public function a_stale_snapshot_is_refreshed_to_the_live_price(): void
    {
        $item = $this->pricedItem(livePrice: 2000, snapshot: 1500);

        $changed = ($this->reprice)($item->cart);

        $this->assertTrue($changed);
        $this->assertSame(2000, (int) $item->fresh()->unit_price);
    }

    #[Test]
    public function it_resolves_at_the_line_quantity_so_price_tiers_apply(): void
    {
        $item = $this->pricedItem(livePrice: 2000, snapshot: 2000, quantity: 2);
        $item->cartable->prices()->create([
            'currency' => Currency::EUR->value,
            'amount' => 1800,
            'minimum_quantity' => 2,
            'price_list_id' => null,
        ]);

        $changed = ($this->reprice)($item->cart->refresh()->load('items.cartable'));

        $this->assertTrue($changed);
        $this->assertSame(1800, (int) $item->fresh()->unit_price);
    }

    #[Test]
    public function a_snapshot_already_matching_the_live_price_reports_no_change(): void
    {
        $item = $this->pricedItem(livePrice: 2000, snapshot: 2000);

        $this->assertFalse(($this->reprice)($item->cart));
        $this->assertSame(2000, (int) $item->fresh()->unit_price);
    }

    #[Test]
    public function a_line_whose_price_cannot_be_resolved_is_left_untouched(): void
    {
        // Zeroing it would let the piece check out free; the unpriced case is
        // the consumer's cart-validation step's to reject.
        $unpriced = TestVariantable::factory()->create();
        $cart = Cart::factory()->create(['currency' => Currency::EUR]);
        $item = CartItem::factory()->for($cart)->create([
            'cartable_type' => $unpriced->getMorphClass(),
            'cartable_id' => $unpriced->id,
            'unit_price' => 1500,
            'quantity' => 1,
        ]);

        $this->assertFalse(($this->reprice)($cart->refresh()->load('items.cartable')));
        $this->assertSame(1500, (int) $item->fresh()->unit_price);
    }

    #[Test]
    public function a_cartable_without_the_prices_capability_is_skipped(): void
    {
        $flat = TestCartable::factory()->create(['unit_price' => 9999]);
        $cart = Cart::factory()->create(['currency' => Currency::EUR]);
        $item = CartItem::factory()->for($cart)->create([
            'cartable_type' => $flat->getMorphClass(),
            'cartable_id' => $flat->id,
            'unit_price' => 1500,
            'quantity' => 1,
        ]);

        $this->assertFalse(($this->reprice)($cart->refresh()->load('items.cartable')));
        $this->assertSame(1500, (int) $item->fresh()->unit_price);
    }

    private function pricedItem(int $livePrice, int $snapshot, int $quantity = 1): CartItem
    {
        $variantable = TestVariantable::factory()->create();
        $variantable->prices()->create([
            'currency' => Currency::EUR->value,
            'amount' => $livePrice,
            'minimum_quantity' => 1,
            'price_list_id' => null,
        ]);

        $cart = Cart::factory()->create(['currency' => Currency::EUR]);

        $item = CartItem::factory()->for($cart)->create([
            'cartable_type' => $variantable->getMorphClass(),
            'cartable_id' => $variantable->id,
            'unit_price' => $snapshot,
            'quantity' => $quantity,
        ]);

        $cart->refresh()->load('items.cartable');

        return $item;
    }
}
