<?php

declare(strict_types=1);

namespace InOtherShops\Storefront;

use InOtherShops\Storefront\Http\Middleware\SetStorefrontContext;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class StorefrontServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/storefront.php', 'storefront');
    }

    public function boot(): void
    {
        if ($this->hasStorefrontModels()) {
            $this->registerRoutes();
        }
    }

    private function hasStorefrontModels(): bool
    {
        return config('storefront.models', []) !== [];
    }

    private function registerRoutes(): void
    {
        $prefix = config('storefront.prefix', 'storefront');
        $middleware = config('storefront.middleware', ['api']);

        Route::prefix("api/{$prefix}")
            ->middleware([...$middleware, SetStorefrontContext::class])
            ->group(__DIR__.'/Http/Routes/api.php');
    }
}
