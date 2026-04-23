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
use InOtherShops\Tests\Stubs\TestCartable;
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
        $this->addToCart = new AddToCart;
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
