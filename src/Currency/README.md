# Currency Domain

Foundational domain providing typed currency representation, locale-aware formatting, and configuration. No database tables — an enum with utilities plus a small display-locale layer.

## Architecture

### Currency Enum

Backed string enum with cases for each supported currency (EUR, USD, GBP).

**Methods:**

- `symbol()` — returns the currency symbol (`€`, `$`, `£`)
- `decimals()` — returns decimal places (all currently 2)
- `format(int $amount, ?string $locale = null)` — formats a cents-based integer into a human-readable string using the display locale's CLDR conventions (separators **and** symbol placement): `'en'` → `€12.50`, `'de'`/`'es'` → `12,50 €`. With no argument the locale resolves ambiently (see Display locale below). Pass an explicit locale in any non-request context — queued mail formats with the order's stored `locale`, never the worker's ambient locale.
- `enabled()` — returns only currencies listed in `config('currency.enabled')`, or all cases if unconfigured
- `enabledOptions()` — returns `['EUR' => 'EUR', ...]` for Filament select fields

### Display locale

`Support\DisplayLocale` resolves which locale's conventions money display strings use:

1. per-request override (set by the middleware below)
2. `config('currency.display_locale')` — escape hatch, ships `null`
3. the application locale

So public surfaces follow the app locale by default — "the language in which they bought" — and a project gains comma-decimal rendering simply by adding a locale, with zero currency config.

`Support\MoneyFormatter` memoizes the underlying intl `NumberFormatter` instances per locale (formatting runs in per-line loops).

### SetMoneyDisplayLocale middleware

`Http\Middleware\SetMoneyDisplayLocale` — register on a Filament panel **as persistent middleware**: `->middleware([SetMoneyDisplayLocale::class], isPersistent: true)`. Livewire AJAX requests (every Save click, table interaction, etc.) only replay persistent middleware — registered non-persistently, the post-save render falls back to the app locale and shows the wrong separator until a full page reload. The middleware resolves the money display locale from the request's `Accept-Language`, so server-rendered admin money text follows the operator's own browser settings — matching what the browser natively does to `type="number"` inputs. It deliberately never calls `app()->setLocale()`: number convention and panel UI language are decoupled. The override is cleared at request **termination** (`app()->terminating()`), never in a try/finally around `$next()` — Livewire's persistent-middleware replay runs the middleware pipeline to completion *before* the component renders, so pipeline-exit cleanup wipes the locale exactly when it's needed (the v0.37.0→v0.37.1 fix). Termination still fires once per request on FPM and Octane, so nothing leaks across requests.

### Configuration

`config/currency.php`:

```php
'enabled' => ['EUR', 'USD'],
'display_locale' => null,   // null = follow the app locale
```

Projects restrict which currencies are available by listing ISO 4217 codes. An empty or null array means all cases are available. `display_locale` pins the money display convention to a fixed locale only when a project's content language and number convention must diverge — every current consumer ships `null`.

## Machine formats

`format()` output is for humans. Storage, gateway payloads, structured data (JSON-LD), and Filament input state stay on raw integer cents or period-hardcoded strings — see the guard rail in `Support\Filament\MoneyFields` (Support namespace) before touching money inputs.

## Dependencies

None. Currency is an independent, foundational domain (requires `ext-intl`, declared in the package's composer.json). Both Pricing and Commerce depend on it; `Support\Filament\MoneyFields` consumes its display-locale layer.

## Future

- Additional currencies as needed (add cases to the enum; 0/3-decimal currencies need `MoneyFields` to grow a Currency parameter — see its docblock)
- Admin-profile locale as an explicit override above `Accept-Language` in the middleware (price format consistency brief §3)
