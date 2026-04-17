<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Cart;

use InOtherShops\Commerce\Cart\Actions\AddToCart;
use InOtherShops\Commerce\Cart\Actions\ClaimCart;
use InOtherShops\Commerce\Cart\Events\CartClaimed;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Tests\Stubs\TestCartable;
use InOtherShops\Tests\Stubs\TestStockable;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

final class ClaimCartTest extends TestCase
{
    use RefreshDatabase;

    private ClaimCart $claimCart;

    private AddToCart $addToCart;

    protected function setUp(): void
    {
        parent::setUp();

        $this->claimCart = new ClaimCart;
        $this->addToCart = new AddToCart;
    }

    #[Test]
    public function it_transfers_ownership_when_owner_has_no_cart(): void
    {
        $guestCart = Cart::factory()->create(['session_token' => 'guest-123']);
        $cartable = TestCartable::factory()->create();
        ($this->addToCart)($guestCart, $cartable);

        $owner = TestStockable::factory()->create(); // any model works as owner

        $result = ($this->claimCart)($guestCart, $owner);

        $this->assertSame($guestCart->id, $result->id);
        $this->assertSame('test_stockable', $result->owner_type);
        $this->assertSame($owner->id, $result->owner_id);
        $this->assertNull($result->session_token);
        $this->assertSame(1, $result->items()->count());
    }

    #[Test]
    public function it_merges_into_existing_owner_cart(): void
    {
        $owner = TestStockable::factory()->create();

        // Owner already has a cart with item A
        $ownerCart = Cart::factory()->create([
            'owner_type' => 'test_stockable',
            'owner_id' => $owner->id,
            'session_token' => null,
        ]);
        $cartableA = TestCartable::factory()->create();
        ($this->addToCart)($ownerCart, $cartableA, quantity: 2);

        // Guest cart has item B
        $guestCart = Cart::factory()->create(['session_token' => 'guest-456']);
        $cartableB = TestCartable::factory()->create();
        ($this->addToCart)($guestCart, $cartableB, quantity: 3);

        $result = ($this->claimCart)($guestCart, $owner);

        $this->assertSame($ownerCart->id, $result->id);
        $this->assertSame(2, $result->items->count());
        $this->assertFalse(Cart::query()->where('id', $guestCart->id)->exists());
    }

    #[Test]
    public function merge_sums_quantity_for_same_cartable(): void
    {
        $owner = TestStockable::factory()->create();
        $cartable = TestCartable::factory()->create();

        $ownerCart = Cart::factory()->create([
            'owner_type' => 'test_stockable',
            'owner_id' => $owner->id,
            'session_token' => null,
        ]);
        ($this->addToCart)($ownerCart, $cartable, quantity: 2);

        $guestCart = Cart::factory()->create(['session_token' => 'guest-789']);
        ($this->addToCart)($guestCart, $cartable, quantity: 3);

        $result = ($this->claimCart)($guestCart, $owner);

        $this->assertSame(1, $result->items->count());
        $this->assertSame(5, $result->items->first()->quantity);
    }

    #[Test]
    public function merge_preserves_unit_price_and_currency_snapshot(): void
    {
        $owner = TestStockable::factory()->create();

        $ownerCart = Cart::factory()->create([
            'owner_type' => 'test_stockable',
            'owner_id' => $owner->id,
            'session_token' => null,
        ]);

        // Guest cart has an item with a price snapshot
        $guestCart = Cart::factory()->create(['session_token' => 'guest-snap']);
        $cartable = TestCartable::factory()->create();
        ($this->addToCart)($guestCart, $cartable);

        // Verify the guest item has a snapshot
        $guestItem = $guestCart->items()->first();
        $this->assertSame(1500, $guestItem->unit_price);
        $this->assertSame(Currency::EUR, $guestItem->currency);

        // Claim — item should be copied to owner cart with snapshot intact
        $result = ($this->claimCart)($guestCart, $owner);

        $mergedItem = $result->items()->first();
        $this->assertSame(1500, $mergedItem->unit_price);
        $this->assertSame(Currency::EUR, $mergedItem->currency);
    }

    #[Test]
    public function it_dispatches_cart_claimed(): void
    {
        Event::fake([CartClaimed::class]);

        $guestCart = Cart::factory()->create(['session_token' => 'guest-evt']);
        $cartable = TestCartable::factory()->create();
        ($this->addToCart)($guestCart, $cartable);

        $owner = TestStockable::factory()->create();

        ($this->claimCart)($guestCart, $owner);

        Event::assertDispatched(CartClaimed::class, 1);
    }
}
