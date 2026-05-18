<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs;

use InOtherShops\Commerce\Cart\Concerns\InteractsWithCart;
use InOtherShops\Commerce\Cart\Contracts\HasCart;
use InOtherShops\Commerce\Order\Contracts\HasOrders;
use InOtherShops\Currency\Enums\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class TestCartable extends Model implements HasCart, HasOrders
{
    use HasFactory;
    use InteractsWithCart;

    protected $guarded = [];

    protected $table = 'test_cartables';

    protected static function newFactory(): Factory
    {
        return new TestCartableFactory;
    }

    public function getCartableUnitPrice(Currency $currency): ?int
    {
        return $this->unit_price;
    }

    public function toOrderLineData(string $currencyCode): array
    {
        return [
            'description' => 'Test cartable #'.$this->getKey(),
            'sku' => null,
            'currency' => $currencyCode,
            'unit_price' => $this->unit_price ?? 0,
            'is_pre_order' => (bool) $this->is_pre_order,
            'expected_ship_date' => $this->expected_ship_date instanceof \DateTimeInterface
                ? $this->expected_ship_date->format('Y-m-d')
                : $this->expected_ship_date,
        ];
    }

    public function availableCurrencies(): array
    {
        return [Currency::EUR->value];
    }

    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
            'is_pre_order' => 'boolean',
            'expected_ship_date' => 'date',
        ];
    }
}
