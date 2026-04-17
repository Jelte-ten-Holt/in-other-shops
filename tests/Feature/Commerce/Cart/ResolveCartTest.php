<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Cart;

use InOtherShops\Commerce\Cart\Actions\ResolveCart;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Tests\Stubs\TestStockable;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

final class ResolveCartTest extends TestCase
{
    use RefreshDatabase;

    private ResolveCart $resolveCart;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolveCart = new ResolveCart;
    }

    #[Test]
    public function it_creates_a_cart_by_session_token(): void
    {
        $cart = ($this->resolveCart)(Currency::EUR, sessionToken: 'sess-abc');

        $this->assertInstanceOf(Cart::class, $cart);
        $this->assertSame('sess-abc', $cart->session_token);
        $this->assertSame(Currency::EUR, $cart->currency);
        $this->assertNull($cart->owner_type);
    }

    #[Test]
    public function it_returns_existing_cart_for_same_session(): void
    {
        $first = ($this->resolveCart)(Currency::EUR, sessionToken: 'sess-dup');
        $second = ($this->resolveCart)(Currency::EUR, sessionToken: 'sess-dup');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Cart::query()->count());
    }

    #[Test]
    public function it_creates_a_cart_by_owner(): void
    {
        $owner = TestStockable::factory()->create();

        $cart = ($this->resolveCart)(Currency::EUR, owner: $owner);

        $this->assertSame('test_stockable', $cart->owner_type);
        $this->assertSame($owner->id, $cart->owner_id);
        $this->assertNull($cart->session_token);
    }

    #[Test]
    public function it_returns_existing_cart_for_same_owner(): void
    {
        $owner = TestStockable::factory()->create();

        $first = ($this->resolveCart)(Currency::EUR, owner: $owner);
        $second = ($this->resolveCart)(Currency::EUR, owner: $owner);

        $this->assertSame($first->id, $second->id);
    }

    #[Test]
    public function owner_takes_precedence_over_session_token(): void
    {
        $owner = TestStockable::factory()->create();

        $cart = ($this->resolveCart)(Currency::EUR, sessionToken: 'ignored', owner: $owner);

        $this->assertSame('test_stockable', $cart->owner_type);
        $this->assertNull($cart->session_token);
    }

    #[Test]
    public function it_throws_when_neither_session_nor_owner_given(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ($this->resolveCart)(Currency::EUR);
    }
}
