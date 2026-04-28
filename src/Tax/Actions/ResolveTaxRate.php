<?php

declare(strict_types=1);

namespace InOtherShops\Tax\Actions;

use InOtherShops\Location\Models\Address;
use InOtherShops\Tax\Enums\TaxCategory;
use InOtherShops\Tax\Models\TaxRate;
use InOtherShops\Tax\Tax;

final class ResolveTaxRate
{
    public function __invoke(Address $address, ?TaxCategory $category = null): ?TaxRate
    {
        $model = Tax::taxRate();

        $country = strtoupper((string) $address->country_code);

        $match = $model::query()
            ->where('country_code', $country)
            ->where(function ($query) use ($category): void {
                $query->whereNull('tax_category');

                if ($category !== null) {
                    $query->orWhere('tax_category', $category->value);
                }
            })
            ->orderByRaw('tax_category IS NULL')
            ->first();

        if ($match !== null) {
            return $match;
        }

        return $model::query()->where('is_default', true)->first();
    }
}
