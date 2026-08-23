<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Cart;

use InOtherShops\Commerce\Cart\Actions\ResolveCart;
use InOtherShops\Commerce\Cart\Http\Support\FindCurrentCart;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

final class FindCurrentCartTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_guest_without_a_cart_gets_null_and_no_row_is_written(): void
    {
        // The regression this class exists for: a shared "cart badge" prop
        // rendered on every page used ResolveCurrentCart, so every anonymous
        // page view minted a carts row. 2,991 of them accumulated on IOW.
        // Session ids must be 40-char alphanumeric or Store::setId() silently
        // discards them and generates a random one instead.
        session()->setId(Str::random(40));

        $this->assertNull(app(FindCurrentCart::class)());
        $this->assertSame(0, Cart::query()->count());
    }

    #[Test]
    public function a_guest_with_a_cart_gets_it_back(): void
    {
        $sessionId = Str::random(40);
        session()->setId($sessionId);
        $created = (new ResolveCart)(Currency::EUR, sessionToken: $sessionId);

        $this->assertSame($sessionId, session()->getId());

        $found = app(FindCurrentCart::class)();

        $this->assertNotNull($found);
        $this->assertSame($created->id, $found->id);
        $this->assertSame(1, Cart::query()->count());
    }
}
