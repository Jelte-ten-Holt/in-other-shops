<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Models;

use InOtherShops\Commerce\Commerce;
use InOtherShops\Currency\Enums\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class OrderLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'unit_price' => 'integer',
            'quantity' => 'integer',
            'line_total' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Commerce::order()::class);
    }

    public function orderable(): MorphTo
    {
        return $this->morphTo();
    }
}
