<?php

declare(strict_types=1);

namespace InOtherShops\Currency\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use InOtherShops\Currency\Support\DisplayLocale;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders money display text in the operator's own number convention.
 *
 * Intended for Filament admin panels: `type="number"` inputs are already
 * localized by the browser to the admin's settings; this middleware brings
 * server-rendered money text (table totals, percent labels) in line by
 * resolving the display locale from Accept-Language — the closest proxy for
 * those browser settings the server can see. Consumers register it on their
 * panel (->middleware([...]) in the panel provider).
 *
 * Deliberately does NOT touch app()->setLocale(): that would flip the
 * panel's UI translations (Filament ships de/es). Number convention and UI
 * language are decoupled — only the money display locale changes here.
 *
 * If admins ever need an explicit preference (a browser whose header lies
 * about its number settings), add a locale field to the admin user profile
 * and let it take precedence over the header here — see the price format
 * consistency brief §3.
 */
final class SetMoneyDisplayLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        DisplayLocale::set($request->getPreferredLanguage());

        try {
            return $next($request);
        } finally {
            // Process state must not leak into the next request on
            // long-running runtimes (Octane, queue-adjacent workers).
            DisplayLocale::clear();
        }
    }
}
