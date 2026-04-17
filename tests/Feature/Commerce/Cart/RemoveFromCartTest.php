<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Cart;

use InOtherShops\Commerce\Cart\Actions\AddToCart;
use InOtherShops\Commerce\Cart\Actions\RemoveFromCart;
use InOtherShops\Commerce\Cart\Events\CartUpdated;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Tests\Stubs\TestCartable;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

final class RemoveFromCartTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_removes_the_item_from_the_cart(): void
    {
        $cart = Cart::factory()->create(['currency' => Currency::EUR->value]);
        $cartable = TestCartable::factory()->create();
        $item = (new AddToCart)($cart, $cartable);

        $this->assertSame(1, $cart->items()->count());

        (new RemoveFromCart)($item);

        $this->assertSame(0, $cart->items()->count());
    }

    #[Test]
    public function it_dispatches_cart_updated(): void
    {
        $cart = Cart::factory()->create(['currency' => Currency::EUR->value]);
        $cartable = TestCartable::factory()->create();
        $item = (new AddToCart)($cart, $cartable);

        Event::fake([CartUpdated::class]);

        (new RemoveFromCart)($item);

        Event::assertDispatched(CartUpdated::class, 1);
    }

    #[Test]
    public function removing_one_item_leaves_others_intact(): void
    {
        $cart = Cart::factory()->create(['currency' => Currency::EUR->value]);
        $itemA = (new AddToCart)($cart, TestCartable::factory()->create());
        $itemB = (new AddToCart)($cart, TestCartable::factory()->create());

        (new RemoveFromCart)($itemA);

        $this->assertSame(1, $cart->items()->count());
        $this->assertTrue($cart->items()->where('id', $itemB->id)->exists());
    }
}
