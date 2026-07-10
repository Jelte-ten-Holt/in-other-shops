<?php

declare(strict_types=1);

namespace InOtherShops\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;

/**
 * Cross-domain, domain-agnostic bootstrapping that belongs to no single domain.
 * Deliberately NOT the abstract {@see DomainServiceProvider} (which every domain
 * extends and boots once per domain) — this registers package-wide primitives
 * exactly once.
 *
 * Registers the `status` Blueprint macro: `$table->status()` standardises every
 * status column at `string(30)` (was a mix of 20/30/255 — `orders.status` at 20
 * was one label away from overflow) with a single-column index by default.
 * Pass `index: false` for a column that carries its own composite index.
 *
 * Also registers the package-wide `shops` config (admin locale) — a
 * cross-cutting setting that belongs to no single domain.
 */
final class SupportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/shops.php', 'shops');

        Blueprint::macro('status', function (bool $index = true) {
            /** @var Blueprint $this */
            $column = $this->string('status', 30);

            if ($index) {
                $this->index('status');
            }

            return $column;
        });
    }

    public function boot(): void
    {
        // Cross-domain admin strings (field labels repeated across resources —
        // Name, Status, Created at, …) live under `shops-common::` so they are
        // translated once, not per domain. Per-domain strings stay in each
        // domain's own `shops-{domain}::` namespace (see DomainServiceProvider).
        $this->loadTranslationsFrom(__DIR__.'/lang', 'shops-common');

        $this->publishes([
            __DIR__.'/config/shops.php' => config_path('shops.php'),
        ], 'shops-config');
    }
}
