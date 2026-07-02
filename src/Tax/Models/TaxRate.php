<?php

declare(strict_types=1);

namespace InOtherShops\Tax\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use InOtherShops\Tax\Database\Factories\TaxRateFactory;
use InOtherShops\Tax\Enums\TaxCategory;

class TaxRate extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static string $factory = TaxRateFactory::class;

    protected function casts(): array
    {
        return [
            'rate_bps' => 'integer',
            'is_default' => 'boolean',
            'tax_category' => TaxCategory::class,
        ];
    }
}
