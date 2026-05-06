<?php

declare(strict_types=1);

namespace InOtherShops\Storefront\Http\Middleware;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Storefront\DTOs\StorefrontContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SetStorefrontContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $currencyCode = $request->header('X-Currency', (string) config('storefront.defaults.currency', 'EUR'));

        $currency = $this->resolveEnabledCurrency($currencyCode);

        $context = new StorefrontContext(currency: $currency);

        app()->instance(StorefrontContext::class, $context);

        return $next($request);
    }

    private function resolveEnabledCurrency(string $currencyCode): Currency
    {
        $enabled = Currency::enabled();
        $currency = Currency::tryFrom($currencyCode);

        if ($currency !== null && in_array($currency, $enabled, true)) {
            return $currency;
        }

        return $enabled[0] ?? Currency::EUR;
    }
}
