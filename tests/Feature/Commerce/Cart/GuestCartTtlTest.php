<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Cart;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Commerce\Cart\Models\CartItem;
use InOtherShops\Commerce\Customer\Models\Customer;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Guest-cart TTL (D7). A guest cart is stamped with commerce.cart.ttl_days at
 * creation and its TTL slides forward on every cart write, so
 * `commerce:prune-carts` reclaims genuinely-abandoned guest carts while an
 * actively-used one never expires under the shopper. Owner carts are untouched.
 */
final class GuestCartTtlTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_guest_cart_is_stamped_with_the_ttl_on_creation(): void
    {
        config(['commerce.cart.ttl_days' => 30]);

        $cart = Cart::factory()->create();

        $this->assertNotNull($cart->expires_at);
        $this->assertEqualsWithDelta(
            now()->addDays(30)->timestamp,
            $cart->expires_at->timestamp,
            10,
        );
    }

    #[Test]
    public function an_owner_cart_is_not_stamped(): void
    {
        $customer = Customer::factory()->create();

        $cart = Cart::factory()->create([
            'owner_type' => $customer->getMorphClass(),
            'owner_id' => $customer->getKey(),
        ]);

        $this->assertNull($cart->expires_at, 'Owner carts are never pruned, so they carry no TTL.');
    }

    #[Test]
    public function a_cart_write_slides_the_ttl_forward(): void
    {
        config(['commerce.cart.ttl_days' => 30]);

        $cart = Cart::factory()->create();
        // Wind the TTL down to near-expiry, bypassing the model hook.
        Cart::query()->whereKey($cart->id)->update(['expires_at' => now()->addDay()]);

        // A cart write: adding an item.
        CartItem::factory()->for($cart)->create();

        $cart->refresh();
        $this->assertTrue(
            $cart->expires_at->greaterThan(now()->addDays(29)),
            'A cart write must slide the TTL back out to the full window.',
        );
    }

    #[Test]
    public function a_guest_cart_is_pruned_once_its_stamped_ttl_lapses(): void
    {
        // End-to-end through the REAL stamping path (not a hand-set expires_at):
        // create, let the TTL lapse, prune deletes it.
        config(['commerce.cart.ttl_days' => 30]);
        $cart = Cart::factory()->create();

        $this->travel(31)->days();

        $this->artisan('commerce:prune-carts')->assertExitCode(0);

        $this->assertNull(Cart::query()->find($cart->id));
    }

    #[Test]
    public function a_cart_within_its_ttl_survives_pruning(): void
    {
        // The kind of cart a live pending order points at: freshly stamped, well
        // within TTL. The abandon window (~60 min) ends the order long before
        // the 30-day cart TTL, so a live order's cart is always within TTL.
        $cart = Cart::factory()->create();

        $this->artisan('commerce:prune-carts')->assertExitCode(0);

        $this->assertNotNull(Cart::query()->find($cart->id));
    }
}
