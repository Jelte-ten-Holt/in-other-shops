<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Models;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Pricing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Price extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'amount' => 'integer',
            'compare_at_amount' => 'integer',
            'minimum_quantity' => 'integer',
        ];
    }

    public function priceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(Pricing::priceList()::class);
    }

    public function formattedAmount(): string
    {
        return $this->currency->format($this->amount);
    }
}
