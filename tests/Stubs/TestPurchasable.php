<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs;

use InOtherShops\Inventory\Concerns\InteractsWithStock;
use InOtherShops\Purchasing\Concerns\InteractsWithPurchasing;
use InOtherShops\Purchasing\Contracts\HasPurchases;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class TestPurchasable extends Model implements HasPurchases
{
    use HasFactory;
    use InteractsWithPurchasing;
    use InteractsWithStock;

    protected $guarded = [];

    protected $table = 'test_purchasables';

    protected static function newFactory(): Factory
    {
        return new TestPurchasableFactory;
    }

    public function tracksStock(): bool
    {
        return (bool) $this->tracks_stock;
    }

    protected function casts(): array
    {
        return [
            'tracks_stock' => 'boolean',
        ];
    }
}
