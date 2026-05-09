<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use InOtherShops\Pricing\Concerns\InteractsWithPrices;
use InOtherShops\Pricing\Contracts\HasPrices;

final class TestPriceable extends Model implements HasPrices
{
    use HasFactory;
    use InteractsWithPrices;

    protected $guarded = [];

    protected $table = 'test_priceables';

    protected static function newFactory(): Factory
    {
        return new TestPriceableFactory;
    }

    protected function casts(): array
    {
        return [];
    }
}
