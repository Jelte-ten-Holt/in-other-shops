<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Commerce\Exceptions\CartReferencesCartableException;
use InOtherShops\Tests\Stubs\TestCartable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class CartDeletionGuardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_blocks_deleting_a_cartable_referenced_by_a_live_cart(): void
    {
        $cartable = TestCartable::factory()->create();
        $this->referenceFromCart($cartable, expiresAt: null);

        try {
            $cartable->delete();
            $this->fail('Expected deletion to be blocked.');
        } catch (CartReferencesCartableException) {
            $this->assertModelExists($cartable);
        }
    }

    #[Test]
    public function it_allows_deleting_a_cartable_referenced_only_by_an_expired_cart(): void
    {
        $cartable = TestCartable::factory()->create();
        $this->referenceFromCart($cartable, expiresAt: now()->subDay());

        $cartable->delete();

        $this->assertModelMissing($cartable);
    }

    #[Test]
    public function it_allows_deleting_an_unreferenced_cartable(): void
    {
        $cartable = TestCartable::factory()->create();

        $cartable->delete();

        $this->assertModelMissing($cartable);
    }

    #[Test]
    public function the_guard_can_be_disabled_by_config(): void
    {
        config(['commerce.cart.guard_cartable_deletion' => false]);

        $cartable = TestCartable::factory()->create();
        $this->referenceFromCart($cartable, expiresAt: null);

        $cartable->delete();

        $this->assertModelMissing($cartable);
    }

    private function referenceFromCart(TestCartable $cartable, ?\DateTimeInterface $expiresAt): void
    {
        $cart = Cart::factory()->create(['expires_at' => $expiresAt]);

        $cartable->cartItems()->create([
            'cart_id' => $cart->id,
            'quantity' => 1,
            'unit_price' => 1000,
            'currency' => 'EUR',
        ]);
    }
}
