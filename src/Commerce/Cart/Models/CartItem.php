<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\Models;

use InOtherShops\Commerce\Commerce;
use InOtherShops\Currency\Enums\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CartItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'currency' => Currency::class,
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Commerce::cart());
    }

    public function cartable(): MorphTo
    {
        return $this->morphTo();
    }
}
