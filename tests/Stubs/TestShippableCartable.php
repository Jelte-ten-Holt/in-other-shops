<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs;

use InOtherShops\Commerce\Cart\Concerns\InteractsWithCart;
use InOtherShops\Commerce\Cart\Contracts\HasCart;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Shipping\Concerns\InteractsWithShippability;
use InOtherShops\Shipping\Contracts\HasShippability;
use InOtherShops\Tax\Contracts\HasTaxCategory;
use InOtherShops\Tax\Enums\TaxCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class TestShippableCartable extends Model implements HasCart, HasShippability, HasTaxCategory
{
    use HasFactory;
    use InteractsWithCart;
    use InteractsWithShippability;

    protected $guarded = [];

    protected $table = 'test_shippable_cartables';

    public ?int $testUnitPrice = 1500;

    protected static function newFactory(): Factory
    {
        return new TestShippableCartableFactory;
    }

    public function getCartableUnitPrice(Currency $currency): ?int
    {
        return $this->testUnitPrice;
    }

    public function requiresShipping(): bool
    {
        return (bool) $this->requires_shipping;
    }

    public function taxCategory(): TaxCategory
    {
        return $this->tax_category;
    }

    protected function casts(): array
    {
        return [
            'requires_shipping' => 'boolean',
            'tax_category' => TaxCategory::class,
        ];
    }
}
