# Changelog

All notable changes to `jelte-ten-holt/in-other-shops` are documented here.
This file starts at v0.52.0; earlier releases are recorded in the git tag
history and in `docs/periphery.md`'s "Last verified" log.

The format is loosely [Keep a Changelog](https://keepachangelog.com/); the
package is pre-1.0, so minor versions may carry breaking changes (all consumers
are pre-launch — single-release-window policy, no deprecation bridges).

## v0.62.0 — 2026-08-23

A read-only way to get the current cart. Additive; nothing existing changes
behaviour.

### Added
- **`InOtherShops\Commerce\Cart\Actions\FindCart`** — the read-only twin of
  `ResolveCart`. Same owner-over-session precedence, returns `?Cart`, never
  writes.
- **`InOtherShops\Commerce\Cart\Http\Support\FindCurrentCart`** — the
  read-only twin of `ResolveCurrentCart`. Returns the visitor's cart or `null`.

### Why
Both consumers shared a `cart` badge prop on **every** Inertia response, and
that prop went through `ResolveCurrentCart` — whose `firstOrCreate` minted a
`carts` row per anonymous page view. Every crawler hit wrote to the database,
and the rows outlived the sessions that caused them: 2,991 accumulated on
in-other-worlds and 103 on bianka-shop-one before it was caught (2026-08-23).

The rule this establishes: **display paths use `Find*`, paths that genuinely
create cart state use `Resolve*`.** A read must not write.

### Notes
- `PruneExpiredCartsCommand` has a related sharp edge, deliberately left alone
  in this release: its `whereNotNull('expires_at')` clause does no protective
  work (owner carts are already excluded by `whereNull('owner_id')`, and
  `ClaimCart` only nulls `expires_at` in the same `update()` that sets
  `owner_id`) — but it makes any guest cart with a null expiry permanently
  un-prunable. That is exactly how the 3,094 rows above became immortal. Worth
  a follow-up predicate that falls back to `created_at`.
- Checkout call sites (`ShowCheckoutFormController`, `StoreCheckoutController`,
  `StoreCheckoutRequest`, and the IOW `Checkout\*Controller`s) still use
  `ResolveCurrentCart`. They sit on write paths, so the create is defensible —
  but `ShowCheckoutFormController` minting a cart for a visitor who has none is
  a design call left open.

## v0.61.0 — 2026-08-22

Localized country names, so a shop can show a shopper "Alemania" instead of
"DE". Additive; nothing existing changes behaviour.

### Added
- **`InOtherShops\Location\Countries`** — `name(code, ?locale)` and
  `options(codes, ?locale)`. Names come from ICU/CLDR via ext-intl (already a
  hard requirement), so **the package ships no country data in any language**
  and a consumer adding a locale costs nothing. `options()` returns
  `{code, name}` rows sorted by the LOCALIZED name using `Collator` — without
  accent-aware collation Spanish sorts "Bélgica" after "Bulgaria", and sorting
  by code at all gives a shopper "AT, AU, BE, BG".
- Config `location.country_names` — **empty by default**, keyed
  `[CODE][locale]`. The escape hatch for the few cases where ICU's wording is
  not the shop's ("Chequia" vs "República Checa"). Resolution order is
  override → ICU → the code itself.

### Notes
- Which countries a shop offers stays the consumer's business (its shipping
  zones, typically); `Countries` only answers what a code is called.
- ICU aliases deprecated codes — `AN` resolves to "Curazao" — so a stale code
  in a consumer's zone list yields a real name rather than an error. Deemed not
  worth an ISO code table to defend against: it needs someone to type a
  deprecated code, and it is visible the moment anyone opens the picker.
- `LocationSchema`'s admin `country_code` field is still a free-text
  `TextInput`. Turning it into a select needs the *shop's* destination list,
  which the package does not have — left to the consumer for now.

## v0.60.0 — 2026-08-22

Checkout consolidation: the quote side of checkout and the voucher HTTP wiring
move into the package, generalized from the two consumers' twinned app code
(brief: projects-root `order-summary-consolidation-brief.md`).

### Added
- **`Commerce/Checkout`** (new namespace — quote-side only; the checkout chain
  stays consumer-owned): `QuoteCheckout` (snapshot subtotal, voucher discount,
  per-shipping-method totals on the PriceBreakdown identity; throws on
  `TaxMode::Exclusive`), `CheckoutQuote`/`ShippingMethodQuote` DTOs,
  `ApplyVoucherController` (reprices, dry-runs, IP-keyed 5/60s limiter,
  translated messages) + `RemoveVoucherController` + `ApplyVoucherRequest`,
  `VoucherSession` (+ `sync()`), and the consumer-mounted `CheckoutRoutes`
  registrar (never auto-registered).
- `Commerce\Cart\Actions\RepriceCart` (from bianka-shop-one) + the documented
  reprice cadence: render / voucher-apply / submit-with-bounce.
- `Cart::subtotalCents()` — the one pre-order snapshot subtotal.
- `Commerce\Order\Support\OrderSummary` — the shopper-facing order projection
  (the package's first presenter); `commerce.order.summary.shows_vat` config.
- Config subtree `commerce.checkout.voucher.*`; shopper-facing lang
  `shops-commerce::checkout.*` (en + es — es is a draft pending review).

### Fixed
- Filament `OrderResource` total recalc now follows
  `subtotal − discount + shipping_cost`; the old `subtotal + tax − discount`
  double-counted inclusive VAT and dropped shipping. The form's shipping input
  now binds to the real `shipping_cost` column (was the phantom
  `_shipping_cost`, which always rendered 0.00 and saved nowhere).
- `CommerceServiceProvider` cart-API gate fallback corrected to `false`
  (a fallback of `true` would auto-register public endpoints if config
  merging ever missed).
- `composer.json` branch alias unstuck from `0.15.x-dev` → `0.60.x-dev`.

## v0.59.0 — 2026-08-22

An unpaid order stops eating a voucher use. New runtime listener — see the
periphery note.

### Added
- **`ReleaseVoucher`** — the counterpart of `ApplyVoucher`. Takes the same
  `SELECT ... FOR UPDATE` lock, decrements `times_used`, floors at zero, treats
  an unknown code as a no-op (the voucher may have been deleted since the order
  was placed), and dispatches the new **`VoucherReleased`** event, which
  `PricingLogSubscriber` writes to the `commerce` audit channel.

- **`ReleaseVoucherOnOrderCancelled`**, registered on `OrderStatusChanged` in
  `CommerceServiceProvider` alongside `SyncInventoryOnOrderStatusChange`. This
  is the voucher-side mirror of releasing a cancelled order's stock: an order
  that is never paid must not consume a voucher use any more than it keeps the
  stock it reserved. Without it, abandoned checkouts permanently ate a
  campaign's uses, and a single-use personal code locked its owner out of their
  own discount the moment they closed the payment tab.

  It fires on every path that cancels a Pending order — the expiry sweep, a
  consumer's cancel-and-replace, an admin cancelling in Filament.

  **`Pending → Cancelled` only**, deliberately narrower than the inventory
  listener's `* → Cancelled`. A Confirmed order that is later cancelled was
  PAID; whether its voucher use returns is a refund-policy decision, and
  answering it here would settle it silently for every consumer.

## v0.58.0 — 2026-08-22

Voucher codes stop being case-sensitive.

### Changed
- **`vouchers.code` is normalized to trimmed upper case on write**, via a
  `Voucher::code` mutator, and both lookups (`CalculateVoucherDiscount`,
  `ApplyVoucher`) normalize their input the same way through the new
  `Voucher::normalizeCode()`. A shopper typing `spring10` now redeems the code
  an admin created as `SPRING10`, and vice versa.

  Explicit normalization rather than leaning on the database: `code` is a plain
  unique column, so case-insensitive matching was previously a property of the
  column's **collation** — true under MySQL's default `utf8mb4_0900_ai_ci`,
  false under SQLite. A redemption that works in production and fails in a
  consumer's test suite is the worst shape a bug can take, and a collation
  change would have silently flipped it.

### Upgrade note
No data migration ships. Existing rows keep whatever casing they were created
with, and a mixed-case stored code will no longer be found by a lookup (which
normalizes to upper case) unless it was already upper case. Uppercase any
existing `vouchers.code` values by hand before relying on redemption —
deliberately not automated, because two codes differing only by case would
collide on the unique index.

## v0.57.0 — 2026-08-22

Vouchers become traceable and stop losing races at the till. Additive migration;
one deliberate behaviour change to voucher redemption at order commit.

### Added
- **`orders.voucher_code`** — a nullable, indexed snapshot of the code that
  produced `orders.discount`. The order stored the amount but never its cause,
  so a discounted order could not be traced to the campaign behind it and
  per-code reporting was impossible. `CreateOrder` writes it from
  `PriceBreakdown::$voucherCode`, which already carried the code and was being
  dropped on the floor.

  A code snapshot rather than a `voucher_id` foreign key, for the same reason
  tax and shipping are snapshotted on an order: the record of a transaction has
  to stay true after the voucher row is edited or deleted.

- **`ApplyVoucher(..., alreadyValidated: true)`** — record the redemption without
  re-running the guard under the row lock. Usage is still incremented inside the
  lock, so `times_used` stays accurate.

- **The admin order form shows the voucher code**, read-only, beside the
  discount, and hidden on orders that carried no voucher.

### Changed
- **A voucher that goes invalid between quote and commit is now honoured
  instead of failing the order.** `CreateOrder` passes `alreadyValidated: true`.

  The window is the microseconds between `CalculateTotal` validating the code to
  arrive at a discount and `CreateOrder` committing the order. Refusing there
  killed a checkout that had already quoted the shopper a price and, in a
  persist-then-pay flow, already sent them to a payment form — a strictly worse
  outcome than the rare overshoot past `max_uses`, which the shop can absorb.

  The overshoot is **recorded, not hidden**: `times_used` still increments, so a
  voucher reads 101/100 and the admin can see what happened.

  A code that matches **no voucher row** still throws `VoucherNotFoundException`
  and still rolls the order back — a missing voucher is not a race, and there is
  nothing to honour or increment. Consumers wanting the strict re-check call
  `ApplyVoucher` themselves; the parameter defaults to `false`.

  Test `CreateOrderTest::a_voucher_invalid_at_apply_time_rolls_back_a_partially_written_order`
  is replaced by `::a_voucher_that_went_invalid_since_it_was_quoted_is_still_honoured`.

## v0.56.0 — 2026-08-19

Media text becomes translatable. No consumer code change is required, but this
migrates data and drops two columns — read the migration note before deploying.

### Changed
- **`media.alt` and `media.description` move out of columns and into the
  `translations` table**, one row per locale. Both are prose a reader sees, so a
  single column was wrong by construction on a multi-locale storefront: one
  photo hangs on a record shared across language editions and could only ever
  carry one language's words. That is harmless for a consumer whose language
  editions are separate rows owning separate media (in-other-worlds), and broken
  for one whose catalog shares a row across locales (bianka).

  `Media` now implements `HasTranslations` with
  `translatableFields() = ['alt', 'description']`, appends both to `toArray()`
  so Inertia payloads keep their shape, and eager-loads `translations` so a
  gallery costs one extra query per batch rather than one per image.

  **Reads are unchanged.** `$media->alt` and `$media->description` still work
  and are now locale-aware, falling back to `translation.fallback` when the
  requested locale has no value — an untranslated caption shows the shop's own
  language rather than a blank space.

  **Writes are unchanged too.** `Media::create(['alt' => 'Hero'])` still works:
  assignments to a translatable field are buffered and written to
  `translation.default` once the row has an id. The one thing that no longer
  works is writing through the query builder
  (`Media::where(...)->update(['alt' => …])`) — there is no column to write.

- **The media form takes one input pair per configured locale.** A single-locale
  consumer sees exactly one alt input and one description textarea, unchanged.
  A multi-locale consumer sees each locale suffixed. The repeater item shape
  changed accordingly — `alt` and `description` are now
  `array<locale, string>` — but `MediaSchema` owns that shape end to end, so no
  consumer code touches it.

### Migration
`2026_08_19_000002_move_media_text_into_translations` copies every non-empty
`alt`/`description` into the consumer's `translation.default` locale and only
then drops the columns, so nothing an editor already wrote is lost. Plain DML
plus a two-column drop — no table rebuild, no foreign keys. The backfill is an
upsert keyed on `translations_unique`, so a re-run after a failed drop (the DDL
is not transactional on MySQL) is safe. Covered by
`MediaTextBackfillMigrationTest`.

## v0.55.0 — 2026-08-19

Additive. No breaking changes; a consumer that never sets the new field stores
null and renders nothing.

### Added
- **Media descriptions.** Every media row now carries a `description` (nullable
  `text`) beside `alt`, edited from a Textarea in `MediaSchema::mediaRepeater()`
  and in `MediaRelationManager`, and carried in the `_media.{collection}` item
  shape that `fillFormData` reads and `saveFormData` writes.

  `alt` and `description` answer different questions and neither is a fallback
  for the other. `alt` is the text a screen reader announces *in place of* the
  image; `description` is prose meant to be read *alongside* it, rendered as a
  visible caption. Consumers that had been leaning on `alt` for a visible
  caption were making assistive tech announce the same words twice.

  Rendering is the consumer's — the package carries the field, not the layout.
  So is precedence against any caption the consumer's own content body already
  supplies for the same image (in-other-worlds lets the closer author win: a
  caption written into a `::media` directive supersedes the media's
  description).

  Migration `2026_08_19_000001_add_description_to_media_table` is additive and
  nullable — a normal `migrate`, no backfill, no table rewrite.

## v0.54.0 — 2026-08-05

Additive. Both changed signatures are unchanged; in-other-worlds, whose catalog
is column-backed, was never affected.

### Fixed
- **Catalog text is resolved through the model, not the query builder.** A live
  500 on every order show/edit page for consumers whose catalog keeps
  `name`/`description` in the `translations` table rather than as columns
  (bianka), plus the same fault latent in storefront listing search and
  `?sort=name`. New public `Support\ModelLabel::for(Model): string` resolves a
  label via `getAttribute()` — name → title → label → slug →
  `"{alias} #{id}"` — and never returns empty.
  `Commerce\Filament\CommerceSchema::buildOrderableOptions()` and
  `Storefront\Actions\ListBrowsables` both route through it.

  (Entry backfilled 2026-08-19 — the tag shipped without one.)

## v0.53.0 — 2026-07-29

Additive. No breaking changes; a collection without the new key behaves exactly
as before.

### Added
- **Per-collection media type restriction.** A `media.collections` entry may now
  carry `types => ['embed']` (strings or `MediaType` cases) to narrow which of
  Upload / External / Embed the admin offers for that collection, alongside the
  existing `cover => false`. New public `MediaSchema::collectionTypes()` is the
  source of truth the Select is wired to.

  The motivating case: a video-embed collection that still offers "Upload" lets
  an editor put a JPEG where a player URL belongs. The upload succeeds, and the
  breakage surfaces later as a dead embed on a public page. Narrowing the
  options removes the wrong answer from the form rather than validating it after
  the fact.

  An unrecognised or empty list falls back to every type rather than none, so a
  config typo can't lock an editor out of their own media form. Save-time
  enforcement comes from Filament, which validates a Select against its own
  option list.

## v0.52.1 — 2026-07-23

Fast-follows from the v0.52.0 supervisor acceptance (recorded in the bianka
money-path brief). No breaking changes.

### Fixed
- **Order lines join the delete-gating (M3 class).** `OrderLinesRelationManager`
  delete + bulk-delete are now hidden unless the owner order `isDeletable()` —
  the lines of a paid order are the durable record of what was sold and must
  survive the same way the order and its addresses do.
- **A late success can no longer un-refund a payment.** The
  `ProcessPaymentWebhook` regression guard now also refuses
  `Refunded`/`PartiallyRefunded` → `Succeeded` (a success delivery delayed past
  a dashboard refund would have flipped the status back and fired
  `PaymentSucceeded` — confirm + ship — for a refunded sale). Refused
  transitions are now `Log::info`'d (payment id, reference, both statuses,
  event id) so out-of-order deliveries are observable.
- **Claiming a guest cart clears its TTL.** v0.52.0 stamps every guest cart's
  `expires_at`; nothing slides it for owner carts, so a claimed cart read as
  dead after 30 idle days — making products in a customer's cart deletable.
  `ClaimCart` now nulls the stamp on claim.
- **`cancelSession` only maps the real state-race.** The
  `InvalidRequestException` → `PaymentNotCancelableException` mapping now
  requires Stripe code `payment_intent_unexpected_state`; any other invalid
  request (`resource_missing`, malformed id — the SDK maps every 400/404 to the
  same class) is rethrown as the real error instead of masquerading as
  "intent live" and being warn-logged forever by the expiry sweep.

### Also shipped in this release — bianka AUDIT-2026-07-04 package wave (PR #8)

Written 2026-07-04, merged into this release window. All additive.

- **SCALE-4** — `coverImage()`/`firstMedia()` resolve from the eager-loaded
  `media` relation when present (0 extra queries; parity with the query path
  pinned incl. pivot-position ordering and collection filters). Kills ~1–2
  queries per card on every storefront render in both consumers.
- **BUG-3** — default `getCartableLabel()` is null-safe: name → slug →
  `"{morph alias} #{key}"`. A name-less cartable no longer 500s cart renders.
- **BUG-7** — `FindOrCreateCartItemStep` absorbs the two-tab double-add race
  via `createOrFirst` + increment-on-lost-race (savepoint-wrapped; the
  surrounding FlowChain transaction survives on all drivers).
- **DRY-1** — new `Pricing::defaultPriceList()` / `forgetDefaultPriceList()`
  request-scoped resolver (Octane/queue-safe, null memoized). Consumers should
  delete their hand-rolled copies on bump (11 across the two apps, tracked in
  the consumers' TODOs).

## v0.52.0 — 2026-07-22

Money-path hardening (from bianka-shop-one AUDIT-2026-07-22). Breaking where noted.

### Fixed
- **Payment status never regresses (M6).** `ProcessPaymentWebhook` no longer
  lets a settled payment (Succeeded / Refunded / PartiallyRefunded) fall back to
  Failed/Pending on an out-of-order webhook delivery.
- **Unmatched settled webhooks retry instead of vanishing (M10).** A succeeded
  or failed event with no matching payment now throws the new
  `UnmatchedWebhookPaymentException` (⇒ non-2xx ⇒ gateway retry) instead of
  returning null (⇒ 204 ⇒ event lost). Thrown before the idempotency insert, so
  a retry lands once the `gateway_reference` exists. **Breaking:** consumers
  whose webhook controller previously relied on a 2xx for an unmatched event now
  see a 5xx — which is the intended "please retry" signal.
- **Cancel-race maps cleanly (M11).** `StripePaymentGateway::cancelSession` maps
  an `InvalidRequestException` from the cancel call (intent raced to succeeded)
  to `PaymentNotCancelableException` rather than letting a raw Stripe error
  escape.
- **Order-expiry is resilient (M5, partial).** `ExpireAbandonedOrders` wraps each
  order so one that throws (gateway outage, driver fault) is logged and skipped
  rather than starving the sweep; the not-cancelable path now logs a warning
  naming the order id and gateway reference.

### Added
- **`Order::isDeletable()`** (Pending AND no payment row) — the D4 delete
  predicate. Filament `EditOrder` delete, and `OrderAddressesRelationManager`
  delete/bulk-delete, are gated on it; the `OrderResource` bulk delete is
  removed (M3/M16). **Breaking:** bulk-deleting orders from the table is no
  longer offered.
- **Guest-cart TTL (D7).** New `commerce.cart.ttl_days` (env `CART_TTL_DAYS`,
  default 30); `Cart` stamps `expires_at` on guest-cart creation and slides it
  forward on every cart write, so `commerce:prune-carts` (previously a no-op for
  want of a stamp) reclaims abandoned guest carts. Owner carts are never stamped
  or pruned.
- **`FakePaymentGateway::markSessionErroring()`** test helper — force
  `cancelSession()` to throw a generic gateway error (distinct from the
  live/`PaymentNotCancelableException` path).

### Notes
- `guardAmountMatches` compares against `intent.amount` (authorized), not
  `amount_received` (captured); equal for the full-capture flow. A received-
  amount guard is deferred (comment in `StripePaymentGateway::parseWebhook`).
