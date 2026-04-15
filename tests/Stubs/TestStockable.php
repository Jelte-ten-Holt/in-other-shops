<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs;

use InOtherShops\Inventory\Concerns\InteractsWithStock;
use InOtherShops\Inventory\Contracts\HasStock;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class TestStockable extends Model implements HasStock
{
    use HasFactory;
    use InteractsWithStock;

    protected $guarded = [];

    protected $table = 'test_stockables';

    protected static function newFactory(): Factory
    {
        return new TestStockableFactory;
    }

    protected function casts(): array
    {
        return [];
    }
}
