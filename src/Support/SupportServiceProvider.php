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
 */
final class SupportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Blueprint::macro('status', function (bool $index = true) {
            /** @var Blueprint $this */
            $column = $this->string('status', 30);

            if ($index) {
                $this->index('status');
            }

            return $column;
        });
    }
}
