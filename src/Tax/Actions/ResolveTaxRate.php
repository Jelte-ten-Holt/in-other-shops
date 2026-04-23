<?php

declare(strict_types=1);

namespace InOtherShops\Tax\Actions;

use InOtherShops\Location\Models\Address;
use InOtherShops\Tax\Models\TaxRate;
use InOtherShops\Tax\Tax;

final class ResolveTaxRate
{
    public function __invoke(Address $address): ?TaxRate
    {
        $model = Tax::taxRate();

        $country = strtoupper((string) $address->country_code);

        $match = $model::query()->where('country_code', $country)->first();

        if ($match !== null) {
            return $match;
        }

        return $model::query()->where('is_default', true)->first();
    }
}
