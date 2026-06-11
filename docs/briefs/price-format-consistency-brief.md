# Brief — Price format consistency (locale-driven money display)

> **Status: RELEASED ✅ (2026-06-11) as v0.37.0** — built same day, all suites green
> (package 866, +13 new across `CurrencyFormatLocaleTest` + `SetMoneyDisplayLocaleMiddlewareTest`;
> in-other-worlds 785; bianka 39), both consumers bumped to `^0.37`, CI + Deploy green,
> live-verified on mayangna.com: ES storefront serves `24,00 €`, EN serves `€24.00`.
> Package commit 7299983; in-other-worlds 56809ca; bianka 9e79efc.
>
> **Build refinements vs. the v4 plan below:** (1) **WI-2 was already built** — orders
> gained a `locale` column in the 2026-05-09 migration and in-other-worlds' checkout already
> passed `app()->getLocale()` into `CreateOrder`; no new column or capture shipped, only the
> mail-side formatting (`OrderConfirmation` + blade now format with `$order->locale`).
> (2) in-other-worlds already shared a `locale` Inertia prop (multilingual work) — reused it
> instead of adding a duplicate `displayLocale` prop. (3) The WI-3 Filament display sweep
> found **zero stragglers** — every admin money column already routed through
> `currency->format()`; the remaining raw `number_format` sites (`CommerceSchema`,
> `OrderResource` `$set()` calls) are *input state* for `type="number"` widgets and correctly
> stay machine-format. (4) `MoneyFieldsTest` runs container-less, so its `percentLabel` cases
> pass an explicit `'en'`; ambient resolution is covered by the new feature tests.
> (5) bianka needed only the bump + panel middleware — no client-side money arithmetic
> exists there, and its checkout (unbuilt) got a periphery note to pass the locale at
> `CreateOrder` time.
>
> **Status: v4 (2026-06-11) — discuss passes 1–3 incorporated; all decisions settled.** Origin: commas and periods both appearing as decimal
> separators on In Other Worlds (storefront bundle savings, admin Edit Product / Edit Order).
> Full formatting inventory swept across all three repos in-session; three mechanisms
> identified and verified against code. **Settled:** storefront/email follow the app locale;
> the admin follows the admin's own settings end-to-end — inputs via the browser's
> `type="number"` localization (`->numeric()` kept; v2's masked-input item dropped), display
> text via a panel middleware resolving the money display locale from `Accept-Language`
> (v3's "accepted residual" eliminated in discuss pass 3); orders store the purchase locale
> so queued mail formats correctly. Target: next package minor (v0.37.0), single release
> window, then bump both consumers.

---

## 1. What we're fixing

Prices render with a comma decimal in some places and a period in others. Three distinct
mechanisms produce this:

1. **`Currency::format()`** (`src/Currency/Enums/Currency.php:29-39`) — the single choke point
   for all server-rendered prices in both consumers. Hardcodes period + symbol-first (`€12.50`).
   Consistent, but locale-blind: a Spanish bianka page shows `€12.50` where the convention is
   `12,50 €`. **→ becomes locale-aware (§2).**
2. **Browser-localized `<input type="number">`** — `MoneyFields::moneyInput()` uses
   `TextInput::numeric()`, which Filament renders as `type="number"`
   (vendor `TextInput::getType()` → `'number'` when `isNumeric()`). Browsers *display* the
   value in the user's UI locale (German system: `10,00` for an underlying `10.00`) but always
   *submit* period-normalized — storage is correct, display only. **→ accepted as intended
   behavior (§3): the admin is a tool for known users; inputs render in the operator's own
   convention.**
3. **`Intl.NumberFormat(undefined)`** in in-other-worlds `resources/js/Support/format.js:35-40`
   — browser-locale formatting for the bundle-savings line (`Pages/Shop/Bundles/Show.vue`),
   plus a duplicate private formatter in `CartShippingSection.vue:36-44` (hardcoded period).
   Both exist because these spots compute arithmetic deltas client-side. **→ fixed to use the
   shared app-locale prop (§2).**

bianka-shop-one has no client-side drift (everything server-formatted) but inherits mechanisms
1 and 2.

**Requirement (Jelte, 2026-06-11, refined over two discuss passes):** the storefront and
order email follow one convention per locale — the app locale, "the language in which they
bought." The admin follows the admin user's own settings (today: browser; future: profile
locale, §3).

## 2. The design — app locale drives the public convention

On In Other Worlds the only locale today is `en` → periods everywhere; when German content
lands (multilingual brief trajectory), `de`-locale visitors get `12,50 €` automatically with
zero config. On bianka, ES pages show `12,50 €`, EN pages `€12.50`.

- **`Currency::format(int $amount, ?string $locale = null)`** — formats via intl
  `NumberFormatter::CURRENCY` (memoized per locale+currency; format() runs in per-line loops).
  Locale resolution: explicit argument → `config('currency.display_locale')` (escape-hatch
  override, ships `null`) → `app()->getLocale()`. CLDR gives separators *and* symbol placement
  — "comma vs period" was always shorthand for the whole convention. `'en'` → `€12.50`,
  `'de'`/`'es'` → `12,50 €`.
- **`MoneyFields::percentLabel()`** takes the same locale (D4): `'de'` → `7,5 %`.
- **Frontend (both consumers):** share the resolved display locale via `HandleInertiaRequests`
  (e.g. `displayLocale` prop, next to `appUrl`). in-other-worlds `format.js::formatCurrency`
  passes it to `Intl.NumberFormat(locale, …)` instead of `undefined`;
  `CartShippingSection.vue` drops its private formatter and imports the shared one. Browser
  `Intl` and PHP intl share CLDR conventions, so client-side deltas match server strings.
  (Dates in `format.js` stay pinned en-US for now; they go locale-aware when a second IOW
  locale actually lands — out of scope here.)

## 3. Admin — the operator's settings drive everything

Settled across discuss passes 2+3: the admin is a tool for known users, so it follows the
admin's own settings, not the app locale. Two halves:

**Inputs stay `->numeric()` (guard rail, do not "fix").** Money/percent inputs keep
`TextInput::numeric()` / `type="number"`. The admin's browser renders them in the admin's own
convention (Jelte's German machine: commas), and the browser submits period-normalized
values, so `dehydrateCents()`'s `(float)` cast is safe **because of** the number input.
**Do not replace these with masked text inputs without reading this:** a masked text input in
a comma-decimal locale submits the literal `"10,50"`, and `(float) "10,50"` is `10.0` — a
silent 50-cent loss on every admin save. If display control over admin inputs is ever truly
needed, the mask must ship in the same change as a strict locale-aware parser
(strip grouping separator, swap decimal to `.`, validate `/^\d+(\.\d{1,2})?$/`, fail
validation on anything else — never best-effort cast). v2 of this brief specced that in full;
it was dropped as not worth the risk for a cosmetic gain.

**Display text follows the browser too, via `Accept-Language`.** Server-rendered admin money
text (order table totals, voucher labels, percent labels) can't see the browser's
input-widget locale directly, but `Accept-Language` is a near-always-correct proxy for it. A
package-shipped panel middleware (`src/Currency/Http/Middleware/SetMoneyDisplayLocale.php`,
registered by each consumer on its Filament panel) resolves the request's preferred locale
and sets the **money display locale context** for the request — slotting into the same
resolution chain as everything else (§2). German browser → `12,50 €` in the order table next
to `10,50` in the input: consistent per the operator's settings, while storefront and email
stay app-locale.

Two constraints on that middleware:

- **It sets only the money display locale — never `app()->setLocale()`.** Filament ships
  German/Spanish UI translations; setting the app locale would flip the entire admin UI
  language. Number convention and UI language are deliberately decoupled here.
- **`Accept-Language` is a proxy, not a guarantee** (and Firefox localizes `type="number"`
  display less reliably than Chrome). Rare mismatches are cosmetic. The exact, explicit fix
  remains available later: a `locale` field on the admin user's profile that **overrides** the
  header in the middleware's resolution (profile → `Accept-Language` → app locale). Deferred
  — the header covers all current admins.

## 4. Order locale persistence (emails)

A queue worker has no request locale — `app()->getLocale()` there is the app default. So
anything rendered outside the request (order mail today; shipping/refund mail later) must
format with a **stored** locale, never the ambient one:

- Nullable `display_locale` (string) column on `orders` — package Commerce migration.
- Captured at order creation: the order-creation path gains an optional locale parameter;
  consumers pass the active app locale at checkout. Null tolerated for legacy rows (falls back
  to the project default at render).
- `OrderConfirmation` (IOW app code) + `mail/order-confirmation.blade.php` format every amount
  with `$order->display_locale`. On bianka this also means the mail renders in the language
  they bought in — naturally aligned with its ES/EN split.

## 5. Periphery map

- **Mail:** `OrderConfirmation` + blade — switch to stored order locale (§4). Appearance
  change intended.
- **Stripe / Payment domain:** integer cents end-to-end — untouched. No formatted string ever
  crosses the gateway boundary.
- **Agent MCP tools:** swept 2026-06-11 — no tool emits formatted prices (only a `Y-m` date
  format in `GetNewsletterIssueStatus`). Envelopes unchanged.
- **Storefront API resources** (`PriceResource`, `CartResource`, `CartItemResource`): their
  `formatted` fields now vary with the request's app locale. Consumers render these verbatim
  in Vue — fine. No known consumer parses them back (verify at build: grep consumers for
  parsing of `formatted`).
- **Filament:** in-other-worlds **extends the package `OrderResource`** (see
  package-tightening brief §2) — no input changes ship anymore (v3), so nothing propagates
  there. Admin *display* columns (order table totals, voucher amount labels) route through
  `Currency::format()`/`percentLabel`, which inside the panel resolve to the
  `Accept-Language`-derived display locale (§3) — sweep for raw `number_format` stragglers.
  New runtime actor: the `SetMoneyDisplayLocale` panel middleware (periphery.md runtime
  section entry; consumers register it in their `AdminPanelProvider`).
- **Logging/audit:** verify at build that log subscribers record raw cents, not formatted
  strings (audit rows must stay locale-independent). If any embed formatted amounts, pin them
  to a machine format.
- **Queued/scheduled contexts:** any current or future job/command that formats money must
  pass an explicit locale (§4 invariant) — sweep package commands (`commerce:prune-carts`
  etc.) to confirm none format money today.
- **Machine formats explicitly out of scope:** JSON-LD / OpenGraph `toFixed(2)` in
  `Products/Show.vue` + `Bundles/Show.vue` (schema.org requires `12.50`), `dehydrateCents`
  output, DB storage (integer cents everywhere). Add a code comment on the JSON-LD sites so
  nobody "fixes" them toward the display locale.
- **ext-intl:** package code now calls `NumberFormatter` directly → add `ext-intl` to the
  package's `composer.json` require. Already a transitive hard requirement via
  `filament/support`, so no new install burden — but the package must own the declaration.
- **IOW locale-default test blind spot** (memory): TestCase pre-binds the URL locale default —
  when testing locale-dependent formatting, make sure tests exercise real locale switching,
  not the pre-bound default.

## 6. Decisions

- **D1 — SETTLED (discuss pass 1): public convention follows the app locale.** Browser-locale
  for the storefront rejected (the visitor's machine, not the site, would own the brand
  surface; emails have no browser locale). Per-project pin rejected as unnecessary — the app
  locale already encodes the right answer per project, and extends automatically when IOW
  gains German. The `display_locale` config key survives only as a `null`-by-default override.
- **D2 — SETTLED:** in-other-worlds configures nothing; app locale `en` → periods today,
  commas arrive with a future `de` locale automatically.
- **D3 — SETTLED:** bianka configures nothing; follows its ES/EN app locale per visitor, and
  order mail uses the stored purchase locale.
- **D4 — percent labels localized:** assumed yes — `7,5 %` next to `12,50 €` is the same
  convention.
- **D5 — SETTLED (discuss pass 2): admin inputs stay `->numeric()`** and render per the
  admin's browser settings. The v2 masked-input work item is dropped; the parser trap is
  documented as a guard rail (§3).
- **D6 — SETTLED (discuss pass 3): admin display text follows the admin's settings too**, via
  the `Accept-Language`-driven panel middleware (§3). The v3 "accepted residual" (comma
  inputs next to period text) is eliminated. Admin-profile locale remains a future *explicit
  override* in the same middleware, not a prerequisite.

## 7. Work items

- **WI-1 (package, Currency):** `Currency::format()` optional-locale signature + resolution
  chain (arg → config override → app locale); memoized `NumberFormatter`;
  `percentLabel()` locale-aware; `display_locale` config key (ships `null`); `ext-intl` in
  composer require.
- **WI-2 (package, Commerce):** `display_locale` column on orders + capture at order creation
  (optional param through the order-creation path; consumers pass the active app locale).
- **WI-3 (package, Filament):** `SetMoneyDisplayLocale` panel middleware
  (`Accept-Language` → money display locale context; never `app()->setLocale()`); sweep all
  admin money/percent *display* sites through the two central helpers; inputs untouched (D5).
  Add the §3 guard-rail comment to `MoneyFields`.
- **WI-4 (in-other-worlds):** `displayLocale` shared prop; `format.js::formatCurrency` uses
  it; `CartShippingSection` dedupe; comment-guard the JSON-LD/OG sites; `OrderConfirmation` +
  mail blade format via the order's stored locale; pass locale at checkout (ProcessCheckout
  path); register the panel middleware in `AdminPanelProvider`.
- **WI-5 (bianka):** shared prop wiring; pass locale at checkout; register the panel
  middleware; verify no client-side money arithmetic exists (none found in sweep — CartDrawer
  renders server strings).
- **WI-6 (tests):** package suite currently asserts `€12.50`-style strings — update; per-locale
  format tests (`en`, `de`, `es`); order-locale capture + mail rendering tests (queued context
  — no ambient request locale); middleware tests (header → display locale; app locale + UI
  language untouched); consumer smoke after bump.
- **WI-7 (docs/release):** package `docs/periphery.md` (changed `format()` signature/output,
  API `formatted` fields, new orders column, new config key = External surface changes → sweep
  both consumers' periphery docs per the cross-consumer audit rule); release v0.37.0; bump
  both consumers in the same window.

## 8. Invariants

- Storage and wire formats never localize: integer cents in DB, Stripe, dehydrated state,
  structured-data markup, agent envelopes.
- Money inputs stay `type="number"` so submitted values are always period-normalized; no
  formatted/localized string is ever parsed back into a number (§3 guard rail).
- Ambient `app()->getLocale()` is trusted only in request context. Queued, scheduled, or
  persisted renderings (mail above all) pass an explicit stored locale.
- Public surfaces (storefront, email): one convention per locale. Admin: the operator's own
  settings.
