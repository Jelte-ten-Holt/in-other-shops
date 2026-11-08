<?php

declare(strict_types=1);

namespace InOtherShops\Tax\Actions;

use InOtherShops\Tax\Enums\TaxCategory;
use InOtherShops\Tax\Models\TaxRate;
use InOtherShops\Tax\Tax;
use InOtherShops\Tax\TaxConfig;

final class ResolveTaxRate
{
    /**
     * @param  string  $countryCode  ISO 3166-1 alpha-2, any case — the country the
     *                               tax follows (the shipping address's, for an order)
     */
    public function __invoke(string $countryCode, ?TaxCategory $category = null): ?TaxRate
    {
        $country = strtoupper($countryCode);

        $match = $this->findCountryRow($country, $category);

        if ($match !== null) {
            return $match;
        }

        // Jurisdiction-aware fallback: when a home jurisdiction is set, only
        // countries inside it inherit the default rate. Countries outside it
        // get a zero-rated export row instead of being silently overcharged.
        if (TaxConfig::homeJurisdiction() !== null) {
            return TaxConfig::isInHomeJurisdiction($country)
                ? $this->defaultRow()
                : $this->exportRow($country, $category);
        }

        return $this->defaultRow();
    }

    private function findCountryRow(string $country, ?TaxCategory $category): ?TaxRate
    {
        $model = Tax::taxRate();

        return $model::query()
            ->where('country_code', $country)
            ->where(function ($query) use ($category): void {
                $query->whereNull('tax_category');

                if ($category !== null) {
                    $query->orWhere('tax_category', $category->value);
                }
            })
            ->orderByRaw('tax_category IS NULL')
            ->first();
    }

    private function defaultRow(): ?TaxRate
    {
        $model = Tax::taxRate();

        return $model::query()->where('is_default', true)->first();
    }

    private function exportRow(string $country, ?TaxCategory $category): TaxRate
    {
        $export = TaxConfig::exportRate();
        $model = Tax::taxRate();

        $rate = new $model;
        $rate->country_code = $country;
        $rate->tax_category = $category?->value;
        $rate->rate_bps = $export['rate_bps'];
        $rate->name = $export['name'];
        $rate->is_default = false;

        return $rate;
    }
}
