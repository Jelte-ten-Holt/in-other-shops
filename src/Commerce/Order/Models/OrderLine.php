<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Models;

use InOtherShops\Commerce\Commerce;
use InOtherShops\Commerce\Database\Factories\OrderLineFactory;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Tax\Enums\TaxCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class OrderLine extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): Factory
    {
        return new OrderLineFactory;
    }

    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'unit_price' => 'integer',
            'quantity' => 'integer',
            'line_total' => 'integer',
            'is_pre_order' => 'boolean',
            'tax_category' => TaxCategory::class,
            'tax_rate_bps' => 'integer',
            'tax_amount' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Commerce::order());
    }

    public function orderable(): MorphTo
    {
        return $this->morphTo();
    }
}
