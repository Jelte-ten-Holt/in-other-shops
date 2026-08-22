<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Checkout;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Commerce\Cart\Models\CartItem;
use InOtherShops\Commerce\Checkout\Actions\QuoteCheckout;
use InOtherShops\Commerce\Checkout\DTOs\ShippingMethodQuote;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Actions\CalculateTotal;
use InOtherShops\Pricing\Exceptions\VoucherInvalidException;
use InOtherShops\Pricing\Exceptions\VoucherMinimumNotMetException;
use InOtherShops\Pricing\Models\Voucher;
use InOtherShops\Tests\Stubs\TestCartable;
use InOtherShops\Tests\Stubs\TestShippableCartable;
use InOtherShops\Tests\Stubs\TestVariantable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * The pre-order quote: snapshot subtotal, voucher discount via the shared
 * validateForUse guard, and the order total per shipping method — all on the
 * PriceBreakdown identity (total = subtotal − discount + shipping), so the
 * quote and the eventual charge share one arithmetic path.
 */
final class QuoteCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private QuoteCheckout $quote;

    protected function setUp(): void
    {
        parent::setUp();

        $this->quote = $this->app->make(QuoteCheckout::class);
    }

    #[Test]
    public function it_sums_the_snapshot_unit_prices_times_quantities(): void
    {
        $cart = $this->cartWith([[1234, 1], [2345, 2]]);

        $quote = ($this->quote)($cart);

        $this->assertSame(1234 + 4690, $quote->subtotal);
        $this->assertSame(0, $quote->discount);
        $this->assertSame(1234 + 4690, $quote->totalWithoutShipping);
        $this->assertNull($quote->voucherCode);
        $this->assertNull($quote->droppedVoucherCode);
    }

    #[Test]
    public function a_line_whose_cartable_was_deleted_still_counts_at_its_snapshot_price(): void
    {
        // One quote term per cart item, never filtered: silently skipping a
        // dangling line would quote a total the chain then refuses to charge,
        // and downstream would shift breakdown lines off their cart items
        // (the G6 positional-VAT invariant).
        $cart = $this->cartWith([[1000, 1], [2500, 1]]);
        config()->set('commerce.cart.guard_cartable_deletion', false);
        $cart->items->last()->cartable->delete();
        $cart->refresh()->load('items.cartable');

        $quote = ($this->quote)($cart);

        $this->assertSame(3500, $quote->subtotal);
    }

    #[Test]
    public function a_fixed_voucher_comes_off_the_goods(): void
    {
        Voucher::factory()->create(['code' => 'TENOFF', 'amount' => 1000]);
        $cart = $this->cartWith([[5000, 1]]);

        $quote = ($this->quote)($cart, 'TENOFF');

        $this->assertSame(5000, $quote->subtotal);
        $this->assertSame(1000, $quote->discount);
        $this->assertSame(4000, $quote->totalWithoutShipping);
        $this->assertSame('TENOFF', $quote->voucherCode);
    }

    #[Test]
    public function a_percentage_voucher_on_an_odd_subtotal_rounds_half_up(): void
    {
        // 3333 at 15% = 499.95 → 500. A round-number input would pass under a
        // rounding-direction mutation; this one doesn't.
        Voucher::factory()->percentage(15)->create(['code' => 'FIFTEEN']);
        $cart = $this->cartWith([[3333, 1]]);

        $quote = ($this->quote)($cart, 'FIFTEEN');

        $this->assertSame(500, $quote->discount);
        $this->assertSame(2833, $quote->totalWithoutShipping);
    }

    #[Test]
    public function a_code_that_no_longer_applies_is_dropped_not_thrown_on_the_render_path(): void
    {
        Voucher::factory()->create(['code' => 'DEAD', 'is_active' => false]);
        $cart = $this->cartWith([[5000, 1]]);

        $quote = ($this->quote)($cart, 'DEAD');

        $this->assertNull($quote->voucherCode);
        $this->assertSame(0, $quote->discount);
        $this->assertSame('DEAD', $quote->droppedVoucherCode);
        $this->assertSame(5000, $quote->totalWithoutShipping);
    }

    #[Test]
    public function the_apply_path_rethrows_so_the_shopper_gets_a_specific_message(): void
    {
        Voucher::factory()->create(['code' => 'BIGCART', 'minimum_order_amount' => 9000]);
        $cart = $this->cartWith([[5000, 1]]);

        $this->expectException(VoucherMinimumNotMetException::class);

        ($this->quote)($cart, 'BIGCART', throwOnInvalidVoucher: true);
    }

    #[Test]
    public function it_quotes_the_total_per_shipping_method_on_the_breakdown_identity(): void
    {
        $this->configureShipping();
        Voucher::factory()->create(['code' => 'TENOFF', 'amount' => 1000]);
        $cart = $this->cartWith([[5000, 1]]);

        $quote = ($this->quote)($cart, 'TENOFF', 'DE');

        $this->assertTrue($quote->requiresShipping);
        $this->assertTrue($quote->canShip);
        $this->assertSame(['standard', 'express'], array_map(
            fn (ShippingMethodQuote $m): string => $m->identifier,
            $quote->methodQuotes,
        ));

        [$standard, $express] = $quote->methodQuotes;
        $this->assertSame(595, $standard->cost);
        $this->assertSame(5000 - 1000 + 595, $standard->total);
        $this->assertSame(999, $express->cost);
        $this->assertSame(5000 - 1000 + 999, $express->total);
    }

    #[Test]
    public function the_free_shipping_threshold_reads_the_pre_discount_subtotal(): void
    {
        // Subtotal 5000 meets the 5000 threshold; subtotal − discount (4000)
        // would not. The voucher comes off the goods, never off the postage
        // qualification — matching the consumer chains' SelectShippingMethod.
        $this->configureShipping(freeShippingThreshold: 5000);
        Voucher::factory()->create(['code' => 'TENOFF', 'amount' => 1000]);
        $cart = $this->cartWith([[5000, 1]]);

        $quote = ($this->quote)($cart, 'TENOFF', 'DE');

        $standard = $quote->methodQuotes[0];
        $this->assertSame(0, $standard->cost);
        $this->assertSame(4000, $standard->total);
    }

    #[Test]
    public function a_digital_cart_needs_no_method_and_can_always_ship(): void
    {
        $this->configureShipping();
        $digital = TestShippableCartable::factory()->create(['requires_shipping' => false]);
        $cart = Cart::factory()->create(['currency' => Currency::EUR]);
        CartItem::factory()->for($cart)->create([
            'cartable_type' => $digital->getMorphClass(),
            'cartable_id' => $digital->id,
            'unit_price' => 2000,
            'quantity' => 1,
        ]);

        $quote = ($this->quote)($cart, countryCode: 'DE');

        $this->assertFalse($quote->requiresShipping);
        $this->assertTrue($quote->canShip);
        $this->assertSame([], $quote->methodQuotes);
    }

    #[Test]
    public function a_country_outside_every_zone_cannot_be_shipped_to(): void
    {
        $this->configureShipping();
        $cart = $this->cartWith([[5000, 1]]);

        $quote = ($this->quote)($cart, countryCode: 'US');

        $this->assertTrue($quote->requiresShipping);
        $this->assertFalse($quote->canShip);
        $this->assertSame([], $quote->methodQuotes);
    }

    #[Test]
    public function a_shippable_cart_without_a_country_cannot_be_quoted_for_shipping(): void
    {
        $this->configureShipping();
        $cart = $this->cartWith([[5000, 1]]);

        $quote = ($this->quote)($cart);

        $this->assertFalse($quote->canShip);
        $this->assertSame([], $quote->methodQuotes);
    }

    #[Test]
    public function the_quoted_total_equals_what_calculate_total_charges_for_the_same_inputs(): void
    {
        // The identity check across the quote/charge pair: a repriced cart
        // (snapshot ≡ live, which is what RepriceCart guarantees at the
        // moments that matter) through QuoteCheckout and through
        // CalculateTotal must land on the same total, voucher and shipping
        // included. Odd amounts + a mixed cart so order-of-operations or
        // rounding drift can't hide.
        $this->configureShipping();
        Voucher::factory()->percentage(15)->create(['code' => 'FIFTEEN']);

        $cart = Cart::factory()->create(['currency' => Currency::EUR]);
        $items = [];

        foreach ([[1111, 2], [3333, 1]] as [$price, $quantity]) {
            $variantable = TestVariantable::factory()->create();
            $variantable->prices()->create([
                'currency' => Currency::EUR->value,
                'amount' => $price,
                'minimum_quantity' => 1,
                'price_list_id' => null,
            ]);
            CartItem::factory()->for($cart)->create([
                'cartable_type' => $variantable->getMorphClass(),
                'cartable_id' => $variantable->id,
                'unit_price' => $price,
                'quantity' => $quantity,
            ]);
            $items[] = ['item' => $variantable, 'quantity' => $quantity, 'description' => 'Piece', 'taxRateBps' => 1900];
        }

        $cart->refresh()->load('items.cartable');

        $quote = ($this->quote)($cart, 'FIFTEEN', 'DE');
        $standard = $quote->methodQuotes[0];

        $breakdown = ($this->app->make(CalculateTotal::class))(
            items: $items,
            currency: Currency::EUR,
            shippingCost: $standard->cost,
            voucherCode: 'FIFTEEN',
        );

        $this->assertSame($breakdown->subtotal, $quote->subtotal);
        $this->assertSame($breakdown->discount, $quote->discount);
        $this->assertSame($breakdown->total, $standard->total);
    }

    #[Test]
    public function the_exclusive_tax_mode_is_not_yet_implemented(): void
    {
        config()->set('pricing.default_tax_mode', 'exclusive');
        $cart = $this->cartWith([[1000, 1]]);

        $this->expectException(RuntimeException::class);

        ($this->quote)($cart);
    }

    #[Test]
    public function an_invalid_voucher_still_throws_the_specific_exception_on_the_apply_path(): void
    {
        Voucher::factory()->create(['code' => 'DEAD', 'is_active' => false]);
        $cart = $this->cartWith([[5000, 1]]);

        $this->expectException(VoucherInvalidException::class);

        ($this->quote)($cart, 'DEAD', throwOnInvalidVoucher: true);
    }

    /**
     * A cart of TestCartable lines with the given [unit_price, quantity]
     * snapshot pairs.
     *
     * @param  array<int, array{int, int}>  $lines
     */
    private function cartWith(array $lines): Cart
    {
        $cart = Cart::factory()->create(['currency' => Currency::EUR]);

        foreach ($lines as [$unitPrice, $quantity]) {
            $cartable = TestCartable::factory()->create();

            CartItem::factory()->for($cart)->create([
                'cartable_type' => $cartable->getMorphClass(),
                'cartable_id' => $cartable->id,
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
            ]);
        }

        return $cart->refresh()->load('items.cartable');
    }

    private function configureShipping(?int $freeShippingThreshold = null): void
    {
        config()->set('shipping.zones', [
            'de' => array_filter([
                'name' => 'Germany',
                'currency' => 'EUR',
                'countries' => ['DE'],
                'free_shipping_threshold' => $freeShippingThreshold,
            ], fn ($value): bool => $value !== null),
        ]);
        config()->set('shipping.methods', [
            'standard' => ['name' => 'Standard', 'sort_order' => 0, 'rates' => ['de' => 595]],
            'express' => ['name' => 'Express', 'sort_order' => 10, 'rates' => ['de' => 999]],
        ]);
    }
}
