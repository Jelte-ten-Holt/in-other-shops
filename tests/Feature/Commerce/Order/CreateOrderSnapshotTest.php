<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Order;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Commerce\Cart\Actions\AddToCart;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Commerce\Order\Actions\CreateOrder;
use InOtherShops\Commerce\Exceptions\CommerceException;
use InOtherShops\Commerce\Order\DTOs\ShippingSnapshot;
use InOtherShops\Commerce\Order\DTOs\TaxSnapshot;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Actions\ApplyVoucher;
use InOtherShops\Pricing\DTOs\PriceBreakdown;
use InOtherShops\Pricing\DTOs\PriceBreakdownLine;
use InOtherShops\Pricing\DTOs\TaxBreakdownLine;
use InOtherShops\Tax\Enums\TaxCategory;
use InOtherShops\Tests\Stubs\TestCartable;
use InOtherShops\Tests\Stubs\TestShippableCartable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class CreateOrderSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private CreateOrder $createOrder;

    private AddToCart $addToCart;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOrder = new CreateOrder($this->app, new ApplyVoucher);
        $this->addToCart = app(\InOtherShops\Commerce\Cart\Actions\AddToCart::class);
    }

    #[Test]
    public function it_snapshots_tax_and_shipping_on_the_order_row(): void
    {
        // Distinct, non-swappable values: `rateBps: 1730` and `countryCode: 'XX'`
        // cannot trade places (one's an int, one's a 2-char string) and the
        // shipping snapshot uses a different cost (777) and method identifier
        // ('express_x') from the tax snapshot — a refactor that mixed up the
        // column assignments (e.g. `tax_rate_bps <- countryCode`) would fail.
        $cart = $this->cartWithItem();

        $breakdown = new PriceBreakdown(
            subtotal: 10000,
            discount: 0,
            tax: 1730,
            shippingCost: 777,
            total: 12507,
            currency: Currency::EUR,
            lines: [],
        );

        $order = ($this->createOrder)(
            cart: $cart,
            breakdown: $breakdown,
            billingAddress: $this->billingAddress(),
            taxSnapshot: new TaxSnapshot(rateBps: 1730, countryCode: 'XX'),
            shippingSnapshot: new ShippingSnapshot(methodIdentifier: 'express_x', cost: 777, currency: 'USD'),
        );

        $this->assertSame(1730, $order->tax_rate_bps);
        $this->assertSame('XX', $order->tax_rate_country_code);
        $this->assertSame('express_x', $order->shipping_method_identifier);
        $this->assertSame(777, $order->shipping_cost);
        $this->assertSame('USD', $order->shipping_cost_currency);
    }

    #[Test]
    public function it_leaves_snapshot_columns_null_when_no_dtos_passed(): void
    {
        $cart = $this->cartWithItem();

        $breakdown = new PriceBreakdown(
            subtotal: 10000,
            discount: 0,
            tax: 0,
            shippingCost: 0,
            total: 10000,
            currency: Currency::EUR,
            lines: [],
        );

        $order = ($this->createOrder)(
            cart: $cart,
            breakdown: $breakdown,
            billingAddress: $this->billingAddress(),
        );

        $this->assertNull($order->tax_rate_bps);
        $this->assertNull($order->tax_rate_country_code);
        $this->assertNull($order->shipping_method_identifier);
        $this->assertSame(0, $order->shipping_cost);
        $this->assertNull($order->shipping_cost_currency);
    }

    #[Test]
    public function it_snapshots_tax_category_and_per_line_rate_from_the_breakdown(): void
    {
        // A digital line and a physical line at *different* rates — proving the
        // per-line rate comes from the priced breakdown, not one cart-wide rate.
        $cart = Cart::factory()->create(['session_token' => 'test-session']);
        ($this->addToCart)($cart, TestShippableCartable::factory()->create([
            'tax_category' => TaxCategory::DigitalServices->value,
        ]));
        ($this->addToCart)($cart, TestCartable::factory()->create());

        // Breakdown lines are aligned by position with the cart items.
        $breakdown = new PriceBreakdown(
            subtotal: 3000,
            discount: 0,
            tax: 384,
            shippingCost: 0,
            total: 3000,
            currency: Currency::EUR,
            lines: [
                new PriceBreakdownLine(description: 'Digital', unitPrice: 1000, quantity: 1, lineTotal: 1000, taxRateBps: 1900),
                new PriceBreakdownLine(description: 'Physical', unitPrice: 2000, quantity: 1, lineTotal: 2000, taxRateBps: 700),
            ],
        );

        $order = ($this->createOrder)(
            cart: $cart,
            breakdown: $breakdown,
            billingAddress: $this->billingAddress(),
            taxSnapshot: new TaxSnapshot(rateBps: 1900, countryCode: 'DE'),
        );

        $lines = $order->lines()->get();

        $this->assertCount(2, $lines);
        $categories = $lines->map(fn ($l) => $l->tax_category)->all();
        $this->assertContains(TaxCategory::DigitalServices, $categories);
        $this->assertContains(TaxCategory::PhysicalGoods, $categories);
        // Each line carries its own rate, in cart order — not a single shared rate.
        $this->assertSame([1900, 700], $lines->pluck('tax_rate_bps')->all());
    }

    #[Test]
    public function it_stores_the_per_bracket_tax_summary_and_no_per_line_tax_amount(): void
    {
        $cart = Cart::factory()->create(['session_token' => 'test-session']);
        ($this->addToCart)($cart, TestCartable::factory()->create(['unit_price' => 3000]));

        $breakdown = new PriceBreakdown(
            subtotal: 3000,
            discount: 0,
            tax: 384,
            shippingCost: 0,
            total: 3000,
            currency: Currency::EUR,
            lines: [
                new PriceBreakdownLine(description: 'Item', unitPrice: 3000, quantity: 1, lineTotal: 3000, taxRateBps: 1900),
            ],
            taxBreakdown: [
                new TaxBreakdownLine(rateBps: 700, taxableBase: 935, tax: 65),
                new TaxBreakdownLine(rateBps: 1900, taxableBase: 1681, tax: 319),
            ],
        );

        $order = ($this->createOrder)(
            cart: $cart,
            breakdown: $breakdown,
            billingAddress: $this->billingAddress(),
            taxSnapshot: new TaxSnapshot(rateBps: 1900, countryCode: 'DE'),
        );

        // The per-bracket summary round-trips through the accessor.
        $summary = $order->fresh()->taxSummary();
        $this->assertCount(2, $summary);
        $this->assertSame(700, $summary[0]->rateBps);
        $this->assertSame(65, $summary[0]->tax);
        $this->assertSame(1900, $summary[1]->rateBps);
        $this->assertSame(319, $summary[1]->tax);

        // Tax is summarised on the order, not distributed per line.
        $this->assertNull($order->lines()->first()->tax_amount);
    }

    #[Test]
    public function it_refuses_to_persist_an_order_whose_lines_do_not_reconcile_with_the_subtotal(): void
    {
        // Audit T1 / G2: the breakdown prices the line at 3000 (e.g. a quantity
        // tier) but the stored line falls back to the cartable's 1500 — so
        // sum(lines) = 1500 against a 3000 subtotal. Fail loud and roll back the
        // whole order rather than persist a total ≠ sum(lines).
        $cart = $this->cartWithItem(); // default TestCartable @ 1500

        $breakdown = new PriceBreakdown(
            subtotal: 3000,
            discount: 0,
            tax: 0,
            shippingCost: 0,
            total: 3000,
            currency: Currency::EUR,
            lines: [
                new PriceBreakdownLine(description: 'Item', unitPrice: 3000, quantity: 1, lineTotal: 3000, taxRateBps: 0),
            ],
        );

        try {
            ($this->createOrder)(
                cart: $cart,
                breakdown: $breakdown,
                billingAddress: $this->billingAddress(),
            );
            $this->fail('Expected CommerceException for a subtotal that does not reconcile with the stored lines.');
        } catch (CommerceException) {
            // expected
        }

        // The transaction rolled back — no half-order persisted.
        $this->assertDatabaseCount('orders', 0);
    }

    #[Test]
    public function default_category_is_physical_goods_when_cartable_does_not_implement_contract(): void
    {
        $cart = $this->cartWithItem();

        $breakdown = new PriceBreakdown(
            subtotal: 1500,
            discount: 0,
            tax: 285,
            shippingCost: 0,
            total: 1785,
            currency: Currency::EUR,
            lines: [],
        );

        $order = ($this->createOrder)(
            cart: $cart,
            breakdown: $breakdown,
            billingAddress: $this->billingAddress(),
            taxSnapshot: new TaxSnapshot(rateBps: 1900, countryCode: 'DE'),
        );

        $line = $order->lines()->first();

        $this->assertSame(TaxCategory::PhysicalGoods, $line->tax_category);
    }

    #[Test]
    public function it_snapshots_is_pre_order_and_expected_ship_date_from_the_cartable_per_line(): void
    {
        // Distinct values per line so a swap (e.g. lines assigned in reverse
        // order) would fail. Mixed pre-order/non-pre-order also guards against
        // an implementation that blanket-applies one line's value to all.
        $stocked = TestCartable::factory()->create([
            'is_pre_order' => false,
            'expected_ship_date' => null,
        ]);

        $preorder = TestCartable::factory()->create([
            'is_pre_order' => true,
            'expected_ship_date' => '2026-09-15',
        ]);

        $cart = Cart::factory()->create(['session_token' => 'test-session']);
        ($this->addToCart)($cart, $stocked);
        ($this->addToCart)($cart, $preorder);

        $breakdown = new PriceBreakdown(
            subtotal: 3000,
            discount: 0,
            tax: 0,
            shippingCost: 0,
            total: 3000,
            currency: Currency::EUR,
            lines: [],
        );

        $order = ($this->createOrder)(
            cart: $cart,
            breakdown: $breakdown,
            billingAddress: $this->billingAddress(),
        );

        $lines = $order->lines()->get()->keyBy(fn ($l) => $l->orderable_id);

        $this->assertFalse((bool) $lines[$stocked->getKey()]->is_pre_order);
        $this->assertNull($lines[$stocked->getKey()]->expected_ship_date);

        $this->assertTrue((bool) $lines[$preorder->getKey()]->is_pre_order);
        $this->assertSame('2026-09-15', $lines[$preorder->getKey()]->expected_ship_date->toDateString());
    }

    #[Test]
    public function it_snapshots_the_locale_when_provided(): void
    {
        $cart = $this->cartWithItem();

        $breakdown = new PriceBreakdown(
            subtotal: 1000,
            discount: 0,
            tax: 0,
            shippingCost: 0,
            total: 1000,
            currency: Currency::EUR,
            lines: [],
        );

        $order = ($this->createOrder)(
            cart: $cart,
            breakdown: $breakdown,
            billingAddress: $this->billingAddress(),
            locale: 'de',
        );

        $this->assertSame('de', $order->locale);
    }

    #[Test]
    public function it_leaves_locale_null_when_not_provided(): void
    {
        $cart = $this->cartWithItem();

        $breakdown = new PriceBreakdown(
            subtotal: 1000,
            discount: 0,
            tax: 0,
            shippingCost: 0,
            total: 1000,
            currency: Currency::EUR,
            lines: [],
        );

        $order = ($this->createOrder)(
            cart: $cart,
            breakdown: $breakdown,
            billingAddress: $this->billingAddress(),
        );

        $this->assertNull($order->locale);
    }

    private function cartWithItem(): Cart
    {
        $cart = Cart::factory()->create(['session_token' => 'test-session']);
        ($this->addToCart)($cart, TestCartable::factory()->create());

        return $cart;
    }

    /**
     * @return array{first_name: string, last_name: string, line_1: string, city: string, postal_code: string, country_code: string}
     */
    private function billingAddress(): array
    {
        return [
            'first_name' => 'Test',
            'last_name' => 'User',
            'line_1' => '1 Test Street',
            'city' => 'Testville',
            'postal_code' => '1234AB',
            'country_code' => 'NL',
        ];
    }
}
