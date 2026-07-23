<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Support;

use InOtherShops\Pricing\Models\PriceList;
use InOtherShops\Pricing\Pricing;

/**
 * Resolves the default price list (`is_default = true`) once per
 * request/container lifecycle. Bound as a *scoped* singleton in
 * PricingServiceProvider, so Octane and queue workers get a fresh instance
 * per request/job instead of caching a stale row across boundaries.
 *
 * Consumers call it via `Pricing::defaultPriceList()`; a null result (no
 * default configured) is memoized too — the second call issues no query
 * either way. `Pricing::forgetDefaultPriceList()` clears the memo within
 * the current scope (e.g. after toggling `is_default` in a test or admin
 * action).
 */
final class DefaultPriceListResolver
{
    private bool $resolved = false;

    private ?PriceList $priceList = null;

    public function resolve(): ?PriceList
    {
        if (! $this->resolved) {
            $this->priceList = Pricing::priceList()::query()
                ->where('is_default', true)
                ->first();
            $this->resolved = true;
        }

        return $this->priceList;
    }

    public function forget(): void
    {
        $this->resolved = false;
        $this->priceList = null;
    }
}
