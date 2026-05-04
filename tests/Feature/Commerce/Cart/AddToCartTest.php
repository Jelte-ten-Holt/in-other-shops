<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Cart;

use InOtherShops\Commerce\Cart\Actions\AddToCart;
use InOtherShops\Commerce\Cart\Events\CartUpdated;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Commerce\Cart\Models\CartItem;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Tests\Stubs\TestCartable;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

final class AddToCartTest extends TestCase
{
    use RefreshDatabase;

    private AddToCart $addToCart;

    protected function setUp(): void
    {
        parent::setUp();

        $this->addToCart = app(\InOtherShops\Commerce\Cart\Actions\AddToCart::class);
    }

    #[Test]
    public function it_creates_a_cart_item_with_snapshot(): void
    {
        $cart = Cart::factory()->create(['currency' => Currency::EUR->value]);
        $cartable = TestCartable::factory()->create();

        $item = ($this->addToCart)($cart, $cartable);

        $this->assertInstanceOf(CartItem::class, $item);
        $this->assertSame(1, $item->quantity);
        $this->assertSame('test_cartable', $item->cartable_type);
        $this->assertSame($cartable->id, $item->cartable_id);
        $this->assertSame(1500, $item->unit_price);
        $this->assertSame(Currency::EUR, $item->currency);
    }

    #[Test]
    public function it_increments_quantity_when_adding_same_cartable(): void
    {
        $cart = Cart::factory()->create(['currency' => Currency::EUR->value]);
        $cartable = TestCartable::factory()->create();

        ($this->addToCart)($cart, $cartable, quantity: 2);
        $item = ($this->addToCart)($cart, $cartable, quantity: 3);

        $this->assertSame(5, $item->quantity);
        $this->assertSame(1, $cart->items()->count());
    }

    #[Test]
    public function it_creates_separate_items_for_different_cartables(): void
    {
        $cart = Cart::factory()->create(['currency' => Currency::EUR->value]);
        $cartableA = TestCartable::factory()->create();
        $cartableB = TestCartable::factory()->create();

        ($this->addToCart)($cart, $cartableA);
        ($this->addToCart)($cart, $cartableB);

        $this->assertSame(2, $cart->items()->count());
    }

    #[Test]
    public function it_snapshots_null_when_cartable_has_no_price(): void
    {
        $cart = Cart::factory()->create(['currency' => Currency::EUR->value]);
        $cartable = TestCartable::factory()->create();
        $cartable->testUnitPrice = null;

        $item = ($this->addToCart)($cart, $cartable);

        $this->assertNull($item->unit_price);
    }

    #[Test]
    public function it_dispatches_cart_updated(): void
    {
        Event::fake([CartUpdated::class]);

        $cart = Cart::factory()->create(['currency' => Currency::EUR->value]);
        $cartable = TestCartable::factory()->create();

        ($this->addToCart)($cart, $cartable);

        Event::assertDispatched(CartUpdated::class, 1);
    }
}
