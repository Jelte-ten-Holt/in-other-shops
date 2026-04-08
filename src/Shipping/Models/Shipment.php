<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Shipment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'cost' => 'integer',
        ];
    }

    public function shippable(): MorphTo
    {
        return $this->morphTo();
    }
}
