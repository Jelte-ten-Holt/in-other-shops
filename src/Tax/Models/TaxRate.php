<?php

declare(strict_types=1);

namespace InOtherShops\Tax\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use InOtherShops\Tax\Database\Factories\TaxRateFactory;

class TaxRate extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): Factory
    {
        return new TaxRateFactory;
    }

    protected function casts(): array
    {
        return [
            'rate_bps' => 'integer',
            'is_default' => 'boolean',
        ];
    }
}
