<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use InOtherShops\Taxonomy\Concerns\InteractsWithCategories;
use InOtherShops\Taxonomy\Concerns\InteractsWithTags;
use InOtherShops\Taxonomy\Contracts\HasCategories;
use InOtherShops\Taxonomy\Contracts\HasTags;

final class TestTaxonomized extends Model implements HasCategories, HasTags
{
    use HasFactory;
    use InteractsWithCategories;
    use InteractsWithTags;

    protected $guarded = [];

    protected $table = 'test_taxonomizeds';

    protected static function newFactory(): Factory
    {
        return new TestTaxonomizedFactory;
    }

    protected function casts(): array
    {
        return [];
    }
}
