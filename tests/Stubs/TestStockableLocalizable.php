<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs;

use InOtherShops\Inventory\Concerns\InteractsWithStock;
use InOtherShops\Inventory\Contracts\HasStock;
use InOtherShops\Translation\Concerns\InteractsWithLocaleGroup;
use InOtherShops\Translation\Contracts\HasLocaleGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class TestStockableLocalizable extends Model implements HasLocaleGroup, HasStock
{
    use HasFactory;
    use InteractsWithLocaleGroup;
    use InteractsWithStock;

    protected $guarded = [];

    protected $table = 'test_stockable_localizables';

    protected static function newFactory(): Factory
    {
        return new TestStockableLocalizableFactory;
    }

    public function tracksStock(): bool
    {
        return true;
    }
}
