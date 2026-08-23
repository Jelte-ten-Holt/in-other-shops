<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Cart;

use InOtherShops\Commerce\Cart\Actions\FindCart;
use InOtherShops\Commerce\Cart\Actions\ResolveCart;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Tests\Stubs\TestStockable;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

final class FindCartTest extends TestCase
{
    use RefreshDatabase;

    private FindCart $findCart;

    private ResolveCart $resolveCart;

    protected function setUp(): void
    {
        parent::setUp();

        $this->findCart = new FindCart;
        $this->resolveCart = new ResolveCart;
    }

    #[Test]
    public function it_returns_null_for_an_unknown_session(): void
    {
        $this->assertNull(($this->findCart)(sessionToken: 'sess-unknown'));
    }

    #[Test]
    public function it_never_creates_a_cart(): void
    {
        ($this->findCart)(sessionToken: 'sess-crawler');
        ($this->findCart)(sessionToken: 'sess-crawler-2');
        ($this->findCart)(owner: TestStockable::factory()->create());

        $this->assertSame(0, Cart::query()->count());
    }

    #[Test]
    public function it_returns_an_existing_cart_for_the_same_session(): void
    {
        $created = ($this->resolveCart)(Currency::EUR, sessionToken: 'sess-abc');

        $found = ($this->findCart)(sessionToken: 'sess-abc');

        $this->assertNotNull($found);
        $this->assertSame($created->id, $found->id);
        $this->assertSame(1, Cart::query()->count());
    }

    #[Test]
    public function it_returns_an_existing_cart_for_the_owner(): void
    {
        $owner = TestStockable::factory()->create();
        $created = ($this->resolveCart)(Currency::EUR, owner: $owner);

        $found = ($this->findCart)(owner: $owner);

        $this->assertNotNull($found);
        $this->assertSame($created->id, $found->id);
    }

    #[Test]
    public function it_returns_null_for_an_owner_without_a_cart(): void
    {
        $this->assertNull(($this->findCart)(owner: TestStockable::factory()->create()));
    }

    #[Test]
    public function owner_takes_precedence_over_session_token(): void
    {
        // Mirrors ResolveCart's precedence — the two must never disagree about
        // which cart "the current cart" is.
        $owner = TestStockable::factory()->create();
        $ownerCart = ($this->resolveCart)(Currency::EUR, owner: $owner);
        ($this->resolveCart)(Currency::EUR, sessionToken: 'sess-guest');

        $found = ($this->findCart)(sessionToken: 'sess-guest', owner: $owner);

        $this->assertSame($ownerCart->id, $found?->id);
    }

    #[Test]
    public function it_requires_a_session_token_or_an_owner(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ($this->findCart)();
    }
}
