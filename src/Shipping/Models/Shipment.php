<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Models;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Shipping\Database\Factories\ShipmentFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Shipment extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): Factory
    {
        return new ShipmentFactory;
    }

    protected function casts(): array
    {
        return [
            'cost' => 'integer',
            'currency' => Currency::class,
        ];
    }

    public function shippable(): MorphTo
    {
        return $this->morphTo();
    }
}
