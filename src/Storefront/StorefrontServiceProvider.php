<?php

declare(strict_types=1);

namespace InOtherShops\Storefront;

use Illuminate\Support\ServiceProvider;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Storefront\DTOs\StorefrontContext;

final class StorefrontServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/storefront.php', 'storefront');

        // Default StorefrontContext for callers (Agent tools, consumer code)
        // that resolve BrowsableResource without first running an HTTP-layer
        // middleware to set per-request context. Defaults to the first enabled
        // currency. A consumer that wants per-request currency can override
        // this binding (e.g. via a middleware) before the resource is built.
        $this->app->bind(StorefrontContext::class, function (): StorefrontContext {
            $enabled = Currency::enabled();

            return new StorefrontContext(currency: $enabled[0] ?? Currency::EUR);
        });
    }
}
