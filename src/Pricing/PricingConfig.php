<?php

declare(strict_types=1);

namespace InOtherShops\Pricing;

use InOtherShops\Pricing\Enums\TaxMode;

final class PricingConfig
{
    /**
     * The default tax mode for orders that don't specify one. Today every order
     * is inclusive (EU B2C); a future B2B path resolves the mode per order.
     */
    public static function defaultTaxMode(): TaxMode
    {
        $value = config('pricing.default_tax_mode', TaxMode::Inclusive->value);

        return $value instanceof TaxMode
            ? $value
            : (TaxMode::tryFrom((string) $value) ?? TaxMode::Inclusive);
    }
}
