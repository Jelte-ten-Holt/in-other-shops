<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Models;

use InOtherShops\Pricing\Pricing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceList extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'priority' => 'integer',
        ];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(Pricing::price()::class);
    }
}
