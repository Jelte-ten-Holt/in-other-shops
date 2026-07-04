<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Cart;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use InOtherShops\Commerce\Cart\Http\Resources\CartItemResource;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Tests\Stubs\TestCartable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * BUG-3 (bianka AUDIT-2026-07-04) — `getCartableLabel(): string` used to
 * return `$this->name` unguarded. A cartable with no name (e.g. a translated
 * model with zero name translations, admin-created) made the trait default
 * return null against a `string` return type — a TypeError that 500'd every
 * cart response containing the item. The default now falls back name → slug
 * → "{morph alias} #{key}" and always returns a string.
 */
final class CartableLabelTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function label_returns_the_name_when_present(): void
    {
        $cartable = TestCartable::factory()->create(['name' => 'Silver Pendant']);

        $this->assertSame('Silver Pendant', $cartable->getCartableLabel());
    }

    #[Test]
    public function label_falls_back_to_the_slug_when_the_name_is_empty(): void
    {
        // TestCartable has no slug column; a consumer model with a slug
        // exposes it as an attribute exactly like this in-memory one.
        $cartable = new TestCartable(['name' => '', 'slug' => 'silver-pendant']);

        $this->assertSame('silver-pendant', $cartable->getCartableLabel());
    }

    #[Test]
    public function label_falls_back_to_morph_alias_and_key_when_name_and_slug_are_missing(): void
    {
        $cartable = TestCartable::factory()->create(['name' => '']);

        $this->assertSame('test_cartable #'.$cartable->getKey(), $cartable->getCartableLabel());
    }

    #[Test]
    public function a_cart_item_whose_cartable_has_no_name_renders_without_throwing(): void
    {
        // The consumer-facing failure mode: CartItemResource calls
        // getCartableLabel() on render, so a null label TypeError'd every
        // subsequent cart response until the row was removed by hand.
        $cartable = TestCartable::factory()->create(['name' => '', 'unit_price' => 1500]);
        $cart = Cart::factory()->create();
        $item = $cart->items()->create([
            'cartable_type' => $cartable->getMorphClass(),
            'cartable_id' => $cartable->getKey(),
            'quantity' => 1,
            'unit_price' => 1500,
            'currency' => $cart->effectiveCurrency(),
        ]);

        $payload = (new CartItemResource($item->fresh()))->toArray(Request::create('/api/cart'));

        $this->assertSame('test_cartable #'.$cartable->getKey(), $payload['cartable']['label']);
    }
}
