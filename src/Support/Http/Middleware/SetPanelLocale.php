<?php

declare(strict_types=1);

namespace InOtherShops\Support\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pins the Filament admin's render locale to `shops.admin_locale`, independent
 * of the storefront (app) locale a consumer sets per request.
 *
 * Register on the panel as PERSISTENT middleware — Livewire AJAX requests
 * (every Save click) only replay persistent middleware. Without it, the
 * post-save render falls back to the app locale until a full reload, so a
 * Spanish-first storefront would flip the admin back to Spanish (or English)
 * mid-session. Mirrors Currency\...\SetMoneyDisplayLocale, which is registered
 * the same way for the same reason.
 */
final class SetPanelLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale((string) config('shops.admin_locale', 'en'));

        return $next($request);
    }
}
