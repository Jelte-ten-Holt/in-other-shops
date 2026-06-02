<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs;

use InOtherShops\Variants\Concerns\InteractsWithVariants;
use InOtherShops\Variants\Contracts\HasVariants;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class TestVariantable extends Model implements HasVariants
{
    use HasFactory;
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
