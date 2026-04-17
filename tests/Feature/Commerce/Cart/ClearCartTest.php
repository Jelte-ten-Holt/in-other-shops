<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Cart;

use InOtherShops\Commerce\Cart\Actions\AddToCart;
use InOtherShops\Commerce\Cart\Actions\ClearCart;
use InOtherShops\Commerce\Cart\Events\CartCleared;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Tests\Stubs\TestCartable;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

final class ClearCartTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_removes_all_items(): void
    {
        $cart = Cart::factory()->create(['currency' => Currency::EUR->value]);
        (new AddToCart)($cart, TestCartable::factory()->create());
        (new AddToCart)($cart, TestCartable::factory()->create());

        $this->assertSame(2, $cart->items()->count());

        (new ClearCart)($cart);

        $this->assertSame(0, $cart->items()->count());
    }

    #[Test]
    public function it_dispatches_cart_cleared(): void
    {
        Event::fake([CartCleared::class]);

        $cart = Cart::factory()->create(['currency' => Currency::EUR->value]);
        (new AddToCart)($cart, TestCartable::factory()->create());

        (new ClearCart)($cart);

        Event::assertDispatched(CartCleared::class, 1);
    }

    #[Test]
    public function clearing_an_empty_cart_is_a_noop(): void
    {
        Event::fake([CartCleared::class]);

        $cart = Cart::factory()->create(['currency' => Currency::EUR->value]);

        (new ClearCart)($cart);

        $this->assertSame(0, $cart->items()->count());
        Event::assertDispatched(CartCleared::class, 1);
    }
}
