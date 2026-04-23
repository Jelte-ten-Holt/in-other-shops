<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Shipping\Database\Factories\ShippingMethodFactory;

class ShippingMethod extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): Factory
    {
        return new ShippingMethodFactory;
    }

    protected function casts(): array
    {
        return [
            'base_cost' => 'integer',
            'currency' => Currency::class,
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
