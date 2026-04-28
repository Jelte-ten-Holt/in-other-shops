<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Cart;

use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Commerce\Cart\Models\CartItem;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Tests\Stubs\TestCartable;
use InOtherShops\Tests\Stubs\TestShippableCartable;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class CartRequiresShippingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function empty_cart_does_not_require_shipping(): void
    {
        $cart = Cart::factory()->create(['currency' => Currency::EUR->value]);

        $this->assertFalse($cart->requiresShipping());
    }

    #[Test]
    public function all_digital_cart_does_not_require_shipping(): void
    {
        $cart = Cart::factory()->create(['currency' => Currency::EUR->value]);

        $this->attachCartable($cart, TestShippableCartable::factory()->create(['requires_shipping' => false]));
        $this->attachCartable($cart, TestShippableCartable::factory()->create(['requires_shipping' => false]));

        $this->assertFalse($cart->requiresShipping());
    }

    #[Test]
    public function mixed_cart_requires_shipping(): void
    {
        $cart = Cart::factory()->create(['currency' => Currency::EUR->value]);

        $this->attachCartable($cart, TestShippableCartable::factory()->create(['requires_shipping' => false]));
        $this->attachCartable($cart, TestShippableCartable::factory()->create(['requires_shipping' => true]));

        $this->assertTrue($cart->requiresShipping());
    }

    #[Test]
    public function cartable_without_shippability_contract_defaults_to_requiring_shipping(): void
    {
        $cart = Cart::factory()->create(['currency' => Currency::EUR->value]);

        $this->attachCartable($cart, TestCartable::factory()->create());

        $this->assertTrue($cart->requiresShipping());
    }

    private function attachCartable(Cart $cart, $cartable): void
    {
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'cartable_type' => $cartable->getMorphClass(),
            'cartable_id' => $cartable->id,
            'currency' => Currency::EUR->value,
        ]);
    }
}
