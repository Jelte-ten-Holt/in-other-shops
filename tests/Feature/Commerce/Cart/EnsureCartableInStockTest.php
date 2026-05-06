<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Cart;

use InOtherShops\Inventory\Exceptions\InsufficientStockException;
use InOtherShops\Commerce\Cart\Actions\AddToCart;
use InOtherShops\Commerce\Cart\Actions\UpdateCartItemQuantity;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Inventory\Actions\AdjustStock;
use InOtherShops\Inventory\Enums\StockMovementReason;
use InOtherShops\Tests\Stubs\TestCartable;
use InOtherShops\Tests\Stubs\TestStockableCartable;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class EnsureCartableInStockTest extends TestCase
{
    use RefreshDatabase;

    private AddToCart $addToCart;

    private UpdateCartItemQuantity $update;

    private AdjustStock $adjustStock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->addToCart = app(AddToCart::class);
        $this->update = app(UpdateCartItemQuantity::class);
        $this->adjustStock = app(AdjustStock::class);
    }

    #[Test]
    public function add_to_cart_rejects_quantity_above_stock_when_backorder_disabled(): void
    {
        $cart = Cart::factory()->create(['currency' => Currency::EUR->value]);
        $cartable = TestStockableCartable::factory()->create([
            'tracks_stock' => true,
            'allow_backorder' => false,
        ]);
        $this->stockUp($cartable, 1);

        $this->expectException(InsufficientStockException::class);
        $this->expectExceptionMessageMatches('/only 1 in stock/');

        ($this->addToCart)($cart, $cartable, quantity: 5);
    }

    #[Test]
    public function add_to_cart_allows_oversell_when_backorder_enabled(): void
    {
        $cart = Cart::factory()->create(['currency' => Currency::EUR->value]);
        $cartable = TestStockableCartable::factory()->create([
            'tracks_stock' => true,
            'allow_backorder' => true,
        ]);
        $this->stockUp($cartable, 1);

        $item = ($this->addToCart)($cart, $cartable, quantity: 5);

        $this->assertSame(5, $item->quantity);
    }

    #[Test]
    public function add_to_cart_allows_any_quantity_when_stock_untracked(): void
    {
        $cart = Cart::factory()->create(['currency' => Currency::EUR->value]);
        $cartable = TestStockableCartable::factory()->create([
            'tracks_stock' => false,
            'allow_backorder' => false,
        ]);

        $item = ($this->addToCart)($cart, $cartable, quantity: 100);

        $this->assertSame(100, $item->quantity);
    }

    #[Test]
    public function add_to_cart_skips_check_for_cartables_without_hasstock(): void
    {
        $cart = Cart::factory()->create(['currency' => Currency::EUR->value]);
        $cartable = TestCartable::factory()->create();

        $item = ($this->addToCart)($cart, $cartable, quantity: 999);

        $this->assertSame(999, $item->quantity);
    }

    #[Test]
    public function add_to_cart_includes_existing_item_quantity_when_checking_stock(): void
    {
        $cart = Cart::factory()->create(['currency' => Currency::EUR->value]);
        $cartable = TestStockableCartable::factory()->create([
            'tracks_stock' => true,
            'allow_backorder' => false,
        ]);
        $this->stockUp($cartable, 3);

        ($this->addToCart)($cart, $cartable, quantity: 2);

        $this->expectException(InsufficientStockException::class);
        $this->expectExceptionMessageMatches('/only 3 in stock/');

        // Already 2 in cart; adding 2 more would request 4 against 3 available.
        ($this->addToCart)($cart, $cartable, quantity: 2);
    }

    #[Test]
    public function add_to_cart_message_says_out_of_stock_when_zero(): void
    {
        $cart = Cart::factory()->create(['currency' => Currency::EUR->value]);
        $cartable = TestStockableCartable::factory()->create([
            'name' => 'Cookie for sale',
            'tracks_stock' => true,
            'allow_backorder' => false,
        ]);

        $this->expectException(InsufficientStockException::class);
        $this->expectExceptionMessage('Cookie for sale is out of stock.');

        ($this->addToCart)($cart, $cartable, quantity: 1);
    }

    #[Test]
    public function update_cart_item_rejects_quantity_above_stock(): void
    {
        $cart = Cart::factory()->create(['currency' => Currency::EUR->value]);
        $cartable = TestStockableCartable::factory()->create([
            'tracks_stock' => true,
            'allow_backorder' => false,
        ]);
        $this->stockUp($cartable, 2);

        $item = ($this->addToCart)($cart, $cartable, quantity: 1);

        $this->expectException(InsufficientStockException::class);

        ($this->update)($item, quantity: 10);
    }

    #[Test]
    public function update_cart_item_allows_quantity_when_under_stock(): void
    {
        $cart = Cart::factory()->create(['currency' => Currency::EUR->value]);
        $cartable = TestStockableCartable::factory()->create([
            'tracks_stock' => true,
            'allow_backorder' => false,
        ]);
        $this->stockUp($cartable, 5);

        $item = ($this->addToCart)($cart, $cartable, quantity: 1);
        $updated = ($this->update)($item, quantity: 4);

        $this->assertNotNull($updated);
        $this->assertSame(4, $updated->quantity);
    }

    private function stockUp(TestStockableCartable $cartable, int $level): void
    {
        ($this->adjustStock)(
            stockable: $cartable,
            quantity: $level,
            reason: StockMovementReason::Received,
        );
    }
}
