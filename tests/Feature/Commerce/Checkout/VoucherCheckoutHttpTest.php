<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Checkout;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Commerce\Cart\Models\CartItem;
use InOtherShops\Commerce\Checkout\Http\CheckoutRoutes;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Models\Voucher;
use InOtherShops\Tests\Stubs\TestCartable;
use InOtherShops\Tests\Stubs\TestVariantable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The voucher apply/remove pair, mounted the way a consumer mounts it
 * (CheckoutRoutes::voucher() inside its own route group — the package never
 * auto-registers these).
 */
final class VoucherCheckoutHttpTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The session id the requests will run under, pinned via the session
     * cookie (the stack carries no cookie encryption) so the guest cart's
     * `session_token` matches the id the cart resolution reads.
     */
    private string $sessionId;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('session.driver', 'array');
        $this->sessionId = str_repeat('a', 40);

        Route::middleware(StartSession::class)->group(function (): void {
            CheckoutRoutes::voucher();
            Route::get('checkout', fn (): string => 'ok')->name('checkout.index');
        });
    }

    /** @param array<string, mixed> $data */
    private function apply(array $data, string $ip = '127.0.0.1'): \Illuminate\Testing\TestResponse
    {
        return $this->call(
            'POST',
            'checkout/voucher',
            $data,
            [config('session.cookie') => $this->sessionId],
            [],
            ['REMOTE_ADDR' => $ip],
        );
    }

    #[Test]
    public function applying_a_real_code_remembers_it_for_the_checkout(): void
    {
        Voucher::factory()->create(['code' => 'TENOFF', 'amount' => 1000]);
        $this->seedCart([[5000, 1]]);

        $response = $this->apply(['voucher_code' => 'TENOFF']);

        $response->assertRedirect(route('checkout.index'));
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('checkout.voucher_code', 'TENOFF');
    }

    #[Test]
    public function a_code_applies_regardless_of_how_it_was_typed(): void
    {
        Voucher::factory()->create(['code' => 'TENOFF', 'amount' => 1000]);
        $this->seedCart([[5000, 1]]);

        $response = $this->apply(['voucher_code' => '  tenoff  ']);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('checkout.voucher_code', 'TENOFF');
    }

    #[Test]
    public function an_unknown_code_is_refused_and_nothing_is_held(): void
    {
        $this->seedCart([[5000, 1]]);

        $response = $this->apply(['voucher_code' => 'NOSUCH']);

        $response->assertSessionHasErrors('voucher_code');
        $response->assertSessionMissing('checkout.voucher_code');
        $this->assertSame(
            __('shops-commerce::checkout.voucher.errors.not_found'),
            session('errors')->first('voucher_code'),
        );
    }

    #[Test]
    public function a_code_under_its_minimum_says_what_the_minimum_is(): void
    {
        Voucher::factory()->create(['code' => 'BIGCART', 'minimum_order_amount' => 9000]);
        $this->seedCart([[5000, 1]]);

        $response = $this->apply(['voucher_code' => 'BIGCART']);

        $response->assertSessionHasErrors('voucher_code');
        $this->assertStringContainsString(
            Currency::EUR->format(9000),
            session('errors')->first('voucher_code'),
        );
    }

    #[Test]
    public function the_minimum_is_judged_against_the_repriced_subtotal_not_the_stale_snapshot(): void
    {
        // Snapshot 4000 is under the 5000 minimum, but the live price moved to
        // 6000. The controller reprices before the dry-run, so the voucher is
        // judged against the subtotal the order would actually charge.
        Voucher::factory()->create(['code' => 'BIGCART', 'amount' => 500, 'minimum_order_amount' => 5000]);

        $variantable = TestVariantable::factory()->create();
        $variantable->prices()->create([
            'currency' => Currency::EUR->value,
            'amount' => 6000,
            'minimum_quantity' => 1,
            'price_list_id' => null,
        ]);
        $cart = $this->emptyCart();
        CartItem::factory()->for($cart)->create([
            'cartable_type' => $variantable->getMorphClass(),
            'cartable_id' => $variantable->id,
            'unit_price' => 4000,
            'quantity' => 1,
        ]);

        $response = $this->apply(['voucher_code' => 'BIGCART']);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('checkout.voucher_code', 'BIGCART');
        $this->assertSame(6000, (int) $cart->items()->first()->unit_price);
    }

    #[Test]
    public function attempts_are_throttled_so_the_code_space_cannot_be_walked(): void
    {
        Voucher::factory()->create(['code' => 'TENOFF', 'amount' => 1000]);
        $this->seedCart([[5000, 1]]);

        foreach (range(1, 5) as $i) {
            $this->apply(['voucher_code' => 'GUESS'.$i]);
        }

        // Even the real code is refused once the budget is spent.
        $response = $this->apply(['voucher_code' => 'TENOFF']);

        $response->assertSessionHasErrors('voucher_code');
        $response->assertSessionMissing('checkout.voucher_code');
    }

    #[Test]
    public function the_throttle_is_per_ip_not_one_shared_bucket(): void
    {
        // A limiter keyed on a constant would pass the single-IP test above
        // unchanged — this pins the per-IP property the enumeration defense
        // rests on.
        Voucher::factory()->create(['code' => 'TENOFF', 'amount' => 1000]);
        $this->seedCart([[5000, 1]]);

        foreach (range(1, 5) as $i) {
            $this->apply(['voucher_code' => 'GUESS'.$i], ip: '10.0.0.1');
        }

        $response = $this->apply(['voucher_code' => 'TENOFF'], ip: '10.0.0.2');

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('checkout.voucher_code', 'TENOFF');
    }

    #[Test]
    public function a_success_clears_the_budget_only_guessing_costs(): void
    {
        Voucher::factory()->create(['code' => 'TENOFF', 'amount' => 1000]);
        Voucher::factory()->create(['code' => 'OTHER', 'amount' => 500]);
        $this->seedCart([[5000, 1]]);

        foreach (range(1, 4) as $i) {
            $this->apply(['voucher_code' => 'GUESS'.$i]);
        }

        $this->apply(['voucher_code' => 'TENOFF'])->assertSessionHasNoErrors();

        // The budget reset on success — a fresh typo and the other real code
        // both still fit.
        $this->apply(['voucher_code' => 'TYPO']);
        $response = $this->apply(['voucher_code' => 'OTHER']);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('checkout.voucher_code', 'OTHER');
    }

    #[Test]
    public function removing_the_code_takes_it_off_the_checkout(): void
    {
        $this->seedCart([[5000, 1]]);

        $response = $this->withSession(['checkout.voucher_code' => 'TENOFF'])
            ->delete('checkout/voucher');

        $response->assertRedirect(route('checkout.index'));
        $response->assertSessionMissing('checkout.voucher_code');
    }

    #[Test]
    public function the_session_key_is_configurable(): void
    {
        config()->set('commerce.checkout.voucher.session_key', 'custom.voucher');
        Voucher::factory()->create(['code' => 'TENOFF', 'amount' => 1000]);
        $this->seedCart([[5000, 1]]);

        $response = $this->apply(['voucher_code' => 'TENOFF']);

        $response->assertSessionHas('custom.voucher', 'TENOFF');
        $response->assertSessionMissing('checkout.voucher_code');
    }

    /**
     * A cart the request will resolve: the test starts the session store, so
     * the cart's `session_token` matches the id the guest resolution reads.
     *
     * @param  array<int, array{int, int}>  $lines
     */
    private function seedCart(array $lines): Cart
    {
        $cart = $this->emptyCart();

        foreach ($lines as [$unitPrice, $quantity]) {
            $cartable = TestCartable::factory()->create();

            CartItem::factory()->for($cart)->create([
                'cartable_type' => $cartable->getMorphClass(),
                'cartable_id' => $cartable->id,
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
            ]);
        }

        return $cart;
    }

    private function emptyCart(): Cart
    {
        return Cart::factory()->create([
            'currency' => Currency::EUR,
            'session_token' => $this->sessionId,
            'owner_type' => null,
            'owner_id' => null,
        ]);
    }
}
