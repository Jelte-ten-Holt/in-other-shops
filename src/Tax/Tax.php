<?php

declare(strict_types=1);

namespace InOtherShops\Tax;

use InOtherShops\Tax\Models\TaxRate;

final class Tax
{
    /** @return class-string<TaxRate> */
    public static function taxRate(): string
    {
        return config('tax.models.tax_rate', TaxRate::class);
    }
}
