<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Models;

use InOtherShops\Currency\Enums\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Shipment extends Model
{
    protected $guarded = [];

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
