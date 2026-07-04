<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Cart;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InOtherShops\Commerce\Cart\FlowChains\AddToCartPayload;
use InOtherShops\Commerce\Cart\FlowChains\Steps\FindOrCreateCartItemStep;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Commerce\Cart\Models\CartItem;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Tests\Stubs\TestCartable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * BUG-7 (bianka AUDIT-2026-07-04) — the two-tab double-add race. Two requests
 * both pre-read "not in cart", both take the create path; the loser's insert
 * hits the (cart_id, cartable_type, cartable_id) unique key and used to
 * escape as a raw QueryException → 500. The step must convert the unique
 * violation into the increment path instead.
 *
 * The race is simulated sequentially: the row is inserted after the pre-read
 * would have run, so the payload arrives with a stale `existingCartItem =
 * null` while the row already exists — exactly the loser's view.
 */
final class FindOrCreateCartItemStepTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_stale_null_pre_read_increments_the_existing_row_instead_of_throwing(): void
    {
        $cartable = TestCartable::factory()->create(['unit_price' => 1500]);
        $cart = Cart::factory()->create();

        // The race winner's row, inserted "between" the loser's pre-read and
        // its insert. Snapshot price 999 ≠ live price 1500, to prove the
        // winner's snapshot stands.
        $winner = $cart->items()->create([
            'cartable_type' => $cartable->getMorphClass(),
            'cartable_id' => $cartable->getKey(),
            'quantity' => 1,
            'unit_price' => 999,
            'currency' => Currency::EUR,
        ]);

        $payload = new AddToCartPayload(cart: $cart, cartable: $cartable, quantity: 2);
        // $payload->existingCartItem stays null — the stale pre-read.

        app(FindOrCreateCartItemStep::class)->handle($payload);

        $this->assertSame(1, CartItem::query()->count(), 'The race must not produce a duplicate line.');
        $this->assertNotNull($payload->cartItem);
        $this->assertSame($winner->id, $payload->cartItem->id);
        $this->assertSame(3, $payload->cartItem->quantity, 'Loser quantity folds into the winner row (1 + 2).');
        $this->assertSame(999, $payload->cartItem->unit_price, 'The winner row keeps its price snapshot.');
    }

    #[Test]
    public function the_race_conversion_survives_inside_a_wrapping_transaction(): void
    {
        // FlowChain runs every chain inside a DB transaction. The unique
        // violation must be absorbed via a savepoint so the surrounding
        // transaction stays usable and commits (on PostgreSQL a violation
        // poisons the transaction without one).
        $cartable = TestCartable::factory()->create(['unit_price' => 1500]);
        $cart = Cart::factory()->create();

        $cart->items()->create([
            'cartable_type' => $cartable->getMorphClass(),
            'cartable_id' => $cartable->getKey(),
            'quantity' => 1,
            'unit_price' => 1500,
            'currency' => Currency::EUR,
        ]);

        $payload = new AddToCartPayload(cart: $cart, cartable: $cartable, quantity: 1);

        DB::transaction(function () use ($payload): void {
            app(FindOrCreateCartItemStep::class)->handle($payload);
        });

        $this->assertSame(1, CartItem::query()->count());
        $this->assertSame(2, CartItem::query()->sole()->quantity);
    }

    #[Test]
    public function the_ordinary_create_path_still_snapshots_price_and_currency(): void
    {
        // Regression guard for the createOrFirst rewrite: a genuinely new
        // line must behave exactly as create() did.
        $cartable = TestCartable::factory()->create(['unit_price' => 1500]);
        $cart = Cart::factory()->create(['currency' => Currency::EUR]);

        $payload = new AddToCartPayload(cart: $cart, cartable: $cartable, quantity: 2);

        app(FindOrCreateCartItemStep::class)->handle($payload);

        $this->assertNotNull($payload->cartItem);
        $this->assertTrue($payload->cartItem->wasRecentlyCreated);
        $this->assertSame(2, $payload->cartItem->quantity);
        $this->assertSame(1500, $payload->cartItem->unit_price);
        $this->assertSame(Currency::EUR, $payload->cartItem->currency);
    }

    #[Test]
    public function a_fresh_pre_read_still_takes_the_plain_increment_path(): void
    {
        // The non-race path: EnsureCartableInStockStep found the row and
        // stashed it, so the step increments without touching createOrFirst.
        $cartable = TestCartable::factory()->create(['unit_price' => 1500]);
        $cart = Cart::factory()->create();

        $existing = $cart->items()->create([
            'cartable_type' => $cartable->getMorphClass(),
            'cartable_id' => $cartable->getKey(),
            'quantity' => 2,
            'unit_price' => 1500,
            'currency' => Currency::EUR,
        ]);

        $payload = new AddToCartPayload(cart: $cart, cartable: $cartable, quantity: 3);
        $payload->existingCartItem = $existing;

        app(FindOrCreateCartItemStep::class)->handle($payload);

        $this->assertSame(1, CartItem::query()->count());
        $this->assertSame($existing->id, $payload->cartItem?->id);
        $this->assertSame(5, $payload->cartItem->quantity);
    }
}
