<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Order;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Commerce\Cart\Actions\AddToCart;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Commerce\Order\Actions\CreateOrder;
use InOtherShops\Commerce\Order\DTOs\ShippingSnapshot;
use InOtherShops\Commerce\Order\DTOs\TaxSnapshot;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Actions\ApplyVoucher;
use InOtherShops\Pricing\DTOs\PriceBreakdown;
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
        $cart = $this->cartWithItem();

        $breakdown = new PriceBreakdown(
            subtotal: 10000,
            discount: 0,
            tax: 2100,
            shippingCost: 500,
            total: 12600,
            currency: Currency::EUR,
            lines: [],
        );

        $order = ($this->createOrder)(
            cart: $cart,
            breakdown: $breakdown,
            billingAddress: $this->billingAddress(),
            taxSnapshot: new TaxSnapshot(rateBps: 2100, countryCode: 'NL'),
            shippingSnapshot: new ShippingSnapshot(methodIdentifier: 'standard', cost: 500, currency: 'EUR'),
        );

        $this->assertSame(2100, $order->tax_rate_bps);
        $this->assertSame('NL', $order->tax_rate_country_code);
        $this->assertSame('standard', $order->shipping_method_identifier);
        $this->assertSame(500, $order->shipping_cost);
        $this->assertSame('EUR', $order->shipping_cost_currency);
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
    public function it_snapshots_tax_category_and_rate_per_line(): void
    {
        $cart = Cart::factory()->create(['session_token' => 'test-session']);
        ($this->addToCart)($cart, TestShippableCartable::factory()->create([
            'tax_category' => TaxCategory::DigitalServices->value,
        ]));
        ($this->addToCart)($cart, TestCartable::factory()->create());

        $breakdown = new PriceBreakdown(
            subtotal: 3000,
            discount: 0,
            tax: 570,
            shippingCost: 0,
            total: 3570,
            currency: Currency::EUR,
            lines: [],
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
        $this->assertSame([1900, 1900], $lines->pluck('tax_rate_bps')->all());
    }

    #[Test]
    public function line_tax_amounts_sum_to_order_tax(): void
    {
        $cart = Cart::factory()->create(['session_token' => 'test-session']);
        ($this->addToCart)($cart, TestCartable::factory()->create(), quantity: 1);
        ($this->addToCart)($cart, TestCartable::factory()->create(), quantity: 2);
        ($this->addToCart)($cart, TestCartable::factory()->create(), quantity: 3);

        $breakdown = new PriceBreakdown(
            subtotal: 9000,
            discount: 0,
            tax: 1710,
            shippingCost: 0,
            total: 10710,
            currency: Currency::EUR,
            lines: [],
        );

        $order = ($this->createOrder)(
            cart: $cart,
            breakdown: $breakdown,
            billingAddress: $this->billingAddress(),
            taxSnapshot: new TaxSnapshot(rateBps: 1900, countryCode: 'DE'),
        );

        $sum = (int) $order->lines()->sum('tax_amount');

        $this->assertSame(1710, $sum);
        $this->assertSame($order->tax, $sum);
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
