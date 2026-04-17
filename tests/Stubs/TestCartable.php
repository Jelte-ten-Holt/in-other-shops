<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs;

use InOtherShops\Commerce\Cart\Concerns\InteractsWithCart;
use InOtherShops\Commerce\Cart\Contracts\HasCart;
use InOtherShops\Currency\Enums\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class TestCartable extends Model implements HasCart
{
    use HasFactory;
    use InteractsWithCart;

    protected $guarded = [];

    protected $table = 'test_cartables';

    /** Price in cents, settable per-instance for test flexibility. */
    public ?int $testUnitPrice = 1500;

    protected static function newFactory(): Factory
    {
        return new TestCartableFactory;
    }

    public function getCartableUnitPrice(Currency $currency): ?int
    {
        return $this->testUnitPrice;
    }

    protected function casts(): array
    {
        return [];
    }
}
