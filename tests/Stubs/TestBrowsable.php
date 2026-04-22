<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs;

use InOtherShops\Inventory\Concerns\InteractsWithStock;
use InOtherShops\Inventory\Contracts\HasStock;
use InOtherShops\Storefront\Contracts\HasStorefrontPresence;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class TestBrowsable extends Model implements HasStock, HasStorefrontPresence
{
    use HasFactory;
    use InteractsWithStock;

    protected $guarded = [];

    protected $table = 'test_browsables';

    protected static function newFactory(): Factory
    {
        return new TestBrowsableFactory;
    }

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    public function getBrowsableName(): string
    {
        return (string) $this->name;
    }

    public function getBrowsableSlug(): string
    {
        return (string) $this->slug;
    }

    public function getBrowsableDescription(): ?string
    {
        return $this->description;
    }

    public function getBrowsableRouteKeyName(): string
    {
        return 'slug';
    }

    public static function browseQuery(): Builder
    {
        return static::query();
    }
}
