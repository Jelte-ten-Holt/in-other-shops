<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Cart;

use InOtherShops\Commerce\Cart\Actions\AddToCart;
use InOtherShops\Commerce\Cart\Actions\UpdateCartItemQuantity;
use InOtherShops\Commerce\Cart\Events\CartUpdated;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Tests\Stubs\TestCartable;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

final class UpdateCartItemQuantityTest extends TestCase
{
    use RefreshDatabase;

    private UpdateCartItemQuantity $update;

    protected function setUp(): void
    {
        parent::setUp();

        $this->update = app(\InOtherShops\Commerce\Cart\Actions\UpdateCartItemQuantity::class);
    }

    #[Test]
    public function it_updates_quantity(): void
    {
        $cart = Cart::factory()->create(['currency' => Currency::EUR->value]);
        $item = (app(\InOtherShops\Commerce\Cart\Actions\AddToCart::class))($cart, TestCartable::factory()->create());

        $result = ($this->update)($item, 5);

        $this->assertNotNull($result);
        $this->assertSame(5, $result->quantity);
    }

    #[Test]
    public function setting_quantity_to_zero_removes_the_item(): void
    {
        $cart = Cart::factory()->create(['currency' => Currency::EUR->value]);
        $item = (app(\InOtherShops\Commerce\Cart\Actions\AddToCart::class))($cart, TestCartable::factory()->create());

        $result = ($this->update)($item, 0);

        $this->assertNull($result);
        $this->assertSame(0, $cart->items()->count());
    }

    #[Test]
    public function setting_negative_quantity_removes_the_item(): void
    {
        $cart = Cart::factory()->create(['currency' => Currency::EUR->value]);
        $item = (app(\InOtherShops\Commerce\Cart\Actions\AddToCart::class))($cart, TestCartable::factory()->create());

        $result = ($this->update)($item, -1);

        $this->assertNull($result);
        $this->assertSame(0, $cart->items()->count());
    }

    #[Test]
    public function it_dispatches_cart_updated_on_quantity_change(): void
    {
        $cart = Cart::factory()->create(['currency' => Currency::EUR->value]);
        $item = (app(\InOtherShops\Commerce\Cart\Actions\AddToCart::class))($cart, TestCartable::factory()->create());

        Event::fake([CartUpdated::class]);

        ($this->update)($item, 3);

        Event::assertDispatched(CartUpdated::class, 1);
    }

    #[Test]
    public function it_dispatches_cart_updated_on_removal(): void
    {
        $cart = Cart::factory()->create(['currency' => Currency::EUR->value]);
        $item = (app(\InOtherShops\Commerce\Cart\Actions\AddToCart::class))($cart, TestCartable::factory()->create());

        Event::fake([CartUpdated::class]);

        ($this->update)($item, 0);

        Event::assertDispatched(CartUpdated::class, 1);
    }
}
