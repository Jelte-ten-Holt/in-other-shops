<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs;

use InOtherShops\Commerce\Cart\Concerns\InteractsWithCart;
use InOtherShops\Commerce\Cart\Contracts\HasCart;
use InOtherShops\Inventory\Concerns\InteractsWithStock;
use InOtherShops\Inventory\Contracts\HasStock;
use InOtherShops\Pricing\Concerns\InteractsWithPrices;
use InOtherShops\Pricing\Contracts\HasPrices;
use InOtherShops\Variants\Concerns\InteractsWithVariants;
use InOtherShops\Variants\Contracts\HasVariants;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A product-shaped variant owner: cart-able and priced/stocked in its own right
 * (as a flat owner would be), and able to own variants. Exercises the price
 * template copy, stock carry, and cart-line label composition.
 */
final class TestVariantable extends Model implements HasCart, HasPrices, HasStock, HasVariants
{
    use HasFactory;
    use InteractsWithCart;
    use InteractsWithPrices;
    use InteractsWithStock;
    use InteractsWithVariants;

    protected $guarded = [];

    protected $table = 'test_variantables';

    protected static function newFactory(): Factory
    {
        return new TestVariantableFactory;
    }

    protected function casts(): array
    {
        return [];
    }
}
