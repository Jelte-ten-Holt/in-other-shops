<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Models;

use InOtherShops\Pricing\Database\Factories\PriceListFactory;
use InOtherShops\Pricing\Pricing;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceList extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): Factory
    {
        return new PriceListFactory;
    }

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'priority' => 'integer',
        ];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(Pricing::price());
    }
}
