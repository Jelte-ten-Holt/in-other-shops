<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs;

use InOtherShops\Commerce\Cart\Concerns\InteractsWithCart;
use InOtherShops\Commerce\Cart\Contracts\HasCart;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Inventory\Concerns\InteractsWithStock;
use InOtherShops\Inventory\Contracts\HasStock;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Stub for testing the cart stock guard. Implements both HasCart and
 * HasStock — exercises the soft Commerce → Inventory dependency that
 * EnsureCartableInStock relies on.
 *
 * Backorder + tracks_stock are settable per-row so a single test file
 * can cover all four guard branches.
 */
final class TestStockableCartable extends Model implements HasCart, HasStock
{
    use HasFactory;
    use InteractsWithCart;
    use InteractsWithStock;

    protected $guarded = [];

    protected $table = 'test_stockable_cartables';

    public ?int $testUnitPrice = 1500;

    protected static function newFactory(): Factory
    {
        return new TestStockableCartableFactory;
    }

    public function getCartableUnitPrice(Currency $currency): ?int
    {
        return $this->testUnitPrice;
    }

    public function tracksStock(): bool
    {
        return (bool) $this->tracks_stock;
    }

    protected function casts(): array
    {
        return [
            'tracks_stock' => 'boolean',
            'allow_backorder' => 'boolean',
        ];
    }
}
