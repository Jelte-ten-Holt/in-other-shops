# Changelog

All notable changes to `jelte-ten-holt/in-other-shops` are documented here.
This file starts at v0.52.0; earlier releases are recorded in the git tag
history and in `docs/periphery.md`'s "Last verified" log.

The format is loosely [Keep a Changelog](https://keepachangelog.com/); the
package is pre-1.0, so minor versions may carry breaking changes (all consumers
are pre-launch — single-release-window policy, no deprecation bridges).

## v0.70.0 — 2026-09-04

### Added
- **`media:prune`** — deletes files under `media.directory` that no `media`
  row references. Orphans accumulated from three sources: the replace no-op
  fixed in v0.68.0, `DeleteVariant` detaching without deleting, and a
  Filament save that throws after the upload has moved out of `livewire-tmp`.
  On staging, more than half the bytes under one consumer's `media/` were
  orphaned and nothing had ever swept them.

  It deletes files, so its promises are invariants with a test each: never a
  referenced original or rung (the reference set spans **every** row on the
  disk regardless of directory — one consumer's rows live under `products/`
  while the sweep runs over `media/`); never outside `media.directory` (one
  **non-recursive** `files()` call, which is also what keeps `livewire-tmp`
  out of reach — sweeping it directly is refused); never younger than
  `--min-age` (default `6h`); idempotent; and **dry run by default**.

  Output is a per-file manifest — size, mtime, ULID-derived upload time, the
  nearest `media` row within ±5 min, and a disposition of `referenced`,
  `young`, `orphan`, `deleted` or `blocked` — plus counts and bytes per
  disposition. It never prints a "done" or "clean" verdict. A file that
  cannot be stat-ed or deleted is recorded `blocked` and the run continues;
  any `blocked` row exits non-zero so a scheduled sweep surfaces it. A forced
  run that deleted or blocked something also writes one `Log::warning` with
  the counts — scheduled, the manifest goes to a stdout nobody reads, and
  once the backlog is swept the nightly run is silent, so a line appearing at
  all is the signal. Registered, **not** auto-scheduled.
- **`media:variants --skipped`** — lists the image rows a previous run
  RECORDED as skipped (`variants = {}`), with their dimensions. The default
  listing cannot show these by design (`{}` is a decision, not a gap), so
  before this the only record of an oversize hero silently served as a 1.7 MB
  original was a log line that staging's `LOG_LEVEL=warning` discarded.

  The emptiness test runs in PHP, not SQL, deliberately: the obvious
  `JSON_LENGTH(variants) = 0` is right on MySQL, but Laravel compiles
  `whereJsonLength()` to SQLite's `json_array_length()`, which returns 0 for
  any JSON value that is **not an array** — so on SQLite that predicate lists
  every row that *succeeded*. Inverted, and silent.

### Fixed
- **`ImageOrientation::apply` no longer swaps EXIF 5 and 7.** Both mirrored
  quarter-turns flipped `IMG_FLIP_VERTICAL` after their rotation; both need
  `IMG_FLIP_HORIZONTAL`. A vertical flip there produces the *other*
  orientation's image exactly — the pair round-trips into each other, both
  keep the transposed shape, and no dimension assertion can tell them apart.
  All eight orientations are now pinned by a 3×2 pixel-grid test derived from
  the EXIF spec's own 0th-row/0th-column table. Low real-world impact: 5 and
  7 are mirror-plus-rotate, which cameras do not emit.
- **The variant job's skip line is a `Log::warning`, not `Log::info`.** Both
  consumers run `LOG_LEVEL=warning` on staging and production, so every skip
  reason the job recorded was written nowhere and the `variants = {}` row was
  the only trace.

## v0.69.2 — 2026-09-04

### Fixed
- **`media.variants.bytes_per_pixel` default 5 → 8.** v0.69.1's estimate let
  the same 35.6 MP staging photo through — 170 MB estimated against 198 MB
  free in the booted worker — and the worker exhausted 256M again. A bare
  `php -r` decode of that file peaks at 140 MB, so the in-process cost is
  above 5.5 bytes per pixel and the old default undercounted it. At 8, a
  256M worker records a skip for anything past ~24 MP. Config default only;
  no code change.

## v0.69.1 — 2026-09-04

### Fixed
- **`GenerateImageVariants` bounds memory at run time, not by megapixels
  alone.** On staging a 7008×5088 photo (35.6 MP, under the 40 MP cap)
  exhausted the 256M queue worker: decoding it needs ~140 MB, and a booted
  worker already holds over 100 MB. An OOM is a fatal, so the worker died,
  the container restarted on every attempt, the job landed in `failed_jobs`
  and the row stayed `null` for every backfill to retry. The job now
  estimates `pixels × media.variants.bytes_per_pixel` (new key, default 5)
  against what is free under `memory_limit` in the running process and
  records the skip (`variants = {}`, reason logged) like every other one.
  On a 256M worker the practical ceiling is ~25–30 MP; the original keeps
  serving as before.

## v0.69.0 — 2026-09-04

Dimensions and the WebP variant ladder — Phase 2 of the media-pipeline build
plan. One additive migration, the package's first queued job, a presenter and
a backfill command. Nothing renders differently until a consumer adopts the
presenter (Phase 3); on bump, the migration runs and the backfill is one
command.

### Added
- **`width`/`height` on every uploaded image**, read from the file header in
  the `saving` hook whenever `path` is dirty — on create and on replace — and
  EXIF-orientation-corrected: an orientation-6 phone photo stores the
  portrait pair the browser shows, not the landscape pair the pixels are
  stored as (F9). Header only, no decode; local disks only.
- **`Media\Jobs\GenerateImageVariants`** — dispatched from `saved` after
  commit when an image upload is created or re-pointed; unique on
  `disk:path` for 120 s; 55 s timeout, 2 tries; carries scalars, not the
  model. Decodes once with GD, applies the EXIF rotation, writes one WebP per
  rung of `media.variants.widths` (400/800/1600) that is narrower than the
  source as `{stem}-w{width}.webp` beside the original, alpha preserved
  (palette PNGs promoted to truecolor first). Every reason not to produce
  rungs — non-image, missing file, non-local disk, no GD decoder for the
  mime, above `media.variants.max_megapixels` (40), not wider than the
  smallest rung — is recorded as `variants = {}` with one log line, never
  thrown. Rung files already on disk are adopted without a decode, so a
  reseed (bianka's staging, every boot) re-creates rows but regenerates
  nothing.
- **`Media\Support\ImagePayload::for(Media)`** — the one image payload
  (`url`, `alt`, `description`, `width`, `height`, `srcset`), same
  convention as `OrderSummary::for()`. `srcset` is `null` until rungs exist,
  else the ascending candidate list with the original as the widest.
  `Media::srcset()` backs it; `url` stays the original (D9).
- **`media:variants`** (`--missing` default, `--all`, `--sync`, `--limit=`)
  — backfill and detection surface; also fills `width`/`height` on rows that
  predate the columns. Registered, not scheduled. "Dispatched 0" on a re-run
  is the done signal.
- Config: `media.variants.{enabled,widths,quality,max_megapixels}`.
- `ext-gd` is now a composer requirement (both consumers ship it); `exif`
  stays optional and guarded. Package CI installs `exif` alongside `gd`.

### Changed
- The replace invariant (v0.68.0) now covers the rungs: a `path` change
  resets `variants`, and the old file's rungs are removed with it after
  commit; `deleting` removes the rungs with the file. Both keep the
  shared-file guard.

### Migration
- `2026_09_04_000001_add_dimensions_and_variants_to_media_table` — three
  nullable columns, additive. After the bump, once per environment:
  `php artisan media:variants` (queued) or `--sync`.

## v0.68.0 — 2026-09-04

The media replace invariant, and the two other sources of orphaned files on
disk. Phase 1 of the media-pipeline build plan; ships alone, no migration, no
consumer code change.

### Fixed
- **Replacing an image on an existing media row now takes effect.**
  `MediaSchema::updateExistingMedia` refreshed the url, the translations and
  the pivot, but never read `$item['path']` — and `media_id` survives in a
  Hidden field, so swapping the file on a repeater row uploaded the new file,
  orphaned it, and left the site serving the old image with no error anywhere.
  The repeater now writes `path`; everything else is the model's job.
- **Replaced files leave the disk, and their metadata stops lying.** Two new
  `Media` boot hooks carry the invariant: `saving` re-reads `filename`,
  `mime_type` and `size` from storage when `path` changes on an existing
  upload row, and `saved` deletes the replaced file inside `DB::afterCommit()`
  unless another row shares it. It lives on the model because there were two
  admin surfaces with two halves of the same bug — `MediaRelationManager`'s
  Edit action did write `path`, but left the metadata describing the replaced
  file and the file itself on disk. Both surfaces are covered, and so is any
  future one.
- **Switching a repeater row's type** (upload ↔ external ↔ embed) is now a
  delete-and-recreate at the same position and cover flag. It used to be
  ignored, leaving `type=upload` beside a `url` while `url()` went on serving
  the old file.
- **`Variants\Actions\DeleteVariant`** deletes the media rows nothing else
  references instead of only detaching them. Every variant deletion used to
  leave its images behind permanently. A media row shared with another parent
  survives.

### Changed
- **`Media::fileIsShared()`** takes two optional arguments,
  `(?string $path = null, ?string $disk = null)`, so the invariant can ask
  about the *old* path. Existing no-arg callers are unaffected.

### Consumers
- No code change. Bump to `^0.68`. The only new visible behaviour is that
  replaced and variant-owned files now actually leave the disk.

## v0.67.0 — 2026-09-01

Form state after a Filament save, and two smaller admin-correctness fixes,
all surfaced by the 2026-09-01 in-other-worlds code-quality review and all
shared by both consumers. Built and proven on the v0.66.0 page-test harness.

### Added
- **`SyncsManualFormState::refillManualFormState()`** — re-hydrates an Edit
  page's form from the saved record. Filament neither refills nor redirects
  after `save()` unless told to, so the Livewire state that produced one save
  was the state the next save read: a one-shot `_stock.adjustment_quantity`
  of 5 applied twice, and a media row created by save #1 (no `media_id` in
  the form yet) was deleted and re-created by save #2. **Consumers: end every
  manual-sync Edit `afterSave()` with it.** `SavesTranslatableForm` now does
  so by construction.
- **`Media::fileIsShared()`** and the matching guard in `Media::deleting` — an
  upload's file is no longer removed from disk while another `media` row
  still points at it. `MediaSchema::saveFormData` creates a replacement row
  *before* it removes an orphan it could not match, so the churn above also
  deleted the file the replacement referenced.

### Changed
- **`TranslationSchema::fields(slugSource:)` derives the slug on create
  only.** A saved record's slug is its URL; retitling it no longer rewrites
  the slug (and 404s every inbound link). The slug field stays editable for a
  deliberate rename.

## v0.66.0 — 2026-09-01

Test-only. The suite's first Filament page-test harness: `tests/Support/BootsFilament`
(provider order is load-bearing — Filament before Livewire), a consumer-shaped
`tests/Stubs/Filament/StubEditableResource` over a `TestEditable` stub with
stock, media and translations, and `docs/writing-tests.md` on how to drive
package Schemas through real Create/Edit pages. No runtime change for consumers.

## v0.65.0 — 2026-08-29

Tag types become a project's own vocabulary. A **widening**: no consumer has to
change, and one that changes nothing keeps exactly the field it has today.

`tags.type` is a free string the package stores and never interprets — every
meaning it carries belongs to the consuming project. Until now the admin offered
it as a bare text input, so the vocabulary lived only in whatever the editor
typed. Projects can now declare their own list, and only their own: an editorial
site partitioning tags by genre and disclosure must not push either onto a shop
that has no use for them.

### Added
- **`config('taxonomy.tag_types')`** — the vocabulary for `tags.type`, declared
  per project. Values may be a plain label (`'genre' => 'Genre'`) or a label
  with an editor-facing description
  (`['label' => 'Disclosure', 'description' => 'How the work was made.']`).
  Defaults to `[]`.
- **`Taxonomy\Support\TagTypes`** — normalizes that config. `isConfigured()`,
  `options(?string $current = null)`, `descriptions()`.

### Changed
- **`Taxonomy\Filament\Resources\TagResource`** — the `type` field is a
  `Select` over the declared vocabulary when a project declares one, and the
  existing free-text `TextInput` when it does not. **Declaring nothing is the
  default and preserves current behaviour**, which is why this needs no
  consumer action.

### Notes
- The select **merges the record's current value into the options** when the
  vocabulary does not contain it. Without that, a tag typed before the list
  existed renders as an empty select and loses its type on the next save of an
  unrelated field — a silent destruction of data the editor never touched.
  Pinned by `TagTypesTest`.
- A malformed (non-array) config degrades to free text rather than fataling in
  the admin.
- The package still assigns **no meaning** to any type value. Consumers that
  give one behaviour — in-other-worlds treats `hidden_on_front` as
  "keep out of public eager-loads" via its own `PublicTags` — keep that
  entirely on their side.

## v0.64.0 — 2026-08-28

Untracked post can ship. A **widening**: no consumer has to change — every
existing callsite still compiles, and both consumers dispatch through the
package's own Filament action rather than calling `DispatchShipment` directly.

### Changed
- **`Shipping\Actions\DispatchShipment::__invoke()`** — tracking is now
  optional:
  `__invoke(Shipment $shipment, ?string $trackingNumber = null, ?string $carrier = null, ?string $trackingUrl = null)`.
  Both tracking parameters were non-nullable and positionally required.
  `shipped_at` is stamped and `ShipmentDispatched` is dispatched **regardless
  of tracking** — that is the whole point of the change.
- **`Shipping\Filament\RelationManagers\ShipmentsRelationManager`** — the
  dispatch form no longer marks `carrier` or `tracking_number` as required
  (both branches of the carrier field: the config-sourced Select and the
  free-text fallback). The callsite normalizes `''` → null on all three
  tracking fields, not just the URL: with `required()` gone an untouched
  Filament input arrives as an empty string, and `carrier = ''` would persist
  as a carrier that reads back as "present, prints as nothing".
  **One action, not two** — a separate "Mark as posted" would be the same
  mechanic wearing a second name.
- **`DispatchShipment::resolveTrackingUrl()`** returns null explicitly when the
  carrier OR the tracking number is absent, rather than relying on the config
  lookup missing. A blank string counts as absent, so a forgetful callsite
  can't template an empty number into a link to a carrier's "not found" page.

### Added
- Lang key `shops-shipping::shipment.form.tracking_number_help` (en + es; the
  es string is a draft pending review, like the rest of that file).

### Why — this fixed a silent failure, not just an ergonomic one
`ShipmentStatus::allowedTransitions()` makes `InTransit` reachable **only**
through `DispatchShipment`, and `Delivered` reachable only through
`InTransit`. Untracked post is a real service a shop sells — at a small-parcel
shop it is the cheaper of two methods and plausibly the majority case — and it
has no tracking number by definition. Such a shipment could therefore never
leave `Ready`, which silently disabled everything downstream of dispatch: no
event to hang a "your order shipped" notification on, and a review-invitation
sweep reading `shipped_at` would find null and never become due. Nothing threw;
the suite stayed green; the feature just never fired. The alternative fixes —
a magic tracking value in a column two features read, or a second app-side
transition path — both work around a package stating something untrue.

## v0.63.0 — 2026-08-26

The `Tracking` domain: attribution graduates out of in-other-worlds and into
the package, so both shops share one implementation instead of two.

### Added
- **`InOtherShops\Tracking`** — a new domain. `CartItemAttribution` and
  `OrderLineAttribution` models, their factories, the `Tracking` model
  registry, and the two FlowChain steps that write them:
  `FlowChains\Steps\RecordCartItemAttribution` (an `AddToCartChain` step,
  reads `metadata.source`, silently skips anything malformed, first-source-wins)
  and `FlowChains\Steps\SnapshotCartItemAttributions` (a checkout step that
  copies attributions onto order lines so they survive the cart).
- **`InOtherShops\Tracking\Contracts\HasCheckoutAttribution`** — implemented
  by a consumer's checkout payload so the snapshot step can reach the cart and
  the order. Checkout chains are app-owned in every consumer, so the package
  cannot name the payload class; this is the usual `Has*` seam. Two one-line
  methods on an existing payload.
- The two attribution migrations, **auto-loaded from the package like every
  other domain's** — not published into consumers.

### Migration adoption — read this before upgrading in-other-worlds
The package's two migration files carry the **same basenames** as the project
migrations in-other-worlds already ran
(`2026_05_27_141346_create_*_attributions_table.php`). The migrator keys
applied migrations by basename across all load paths, so on in-other-worlds the
package pair matches the existing `migrations` rows and is skipped — its live
tables and data are untouched — while a fresh install runs them for real.

The consumer MUST **delete** its project copies rather than empty them: a
lingering project file shadows the package copy in the migrator's path merge.

Consequence worth knowing: `down()` now targets in-other-worlds' live tables, so
a `migrate:rollback` walking back that far drops real attribution history.

Schema note, measured on MySQL 8.0.46: the pair produces three keys per table —
PRIMARY, the unique on the FK column, and the `source_type`/`source_id` index.
There is no separate `*_foreign` backing index, because InnoDB reuses the unique
index for that, and the declaration order of `constrained()` and `unique()` does
not change the result.

### Notes
- Tracking ships **no log subscriber**, deliberately — the attribution row is
  itself the record, and logging it would double a money-path write for nothing
  an operator would read. First domain to skip that convention on purpose.
- Tracking ships **no read surface**. `order_line_attributions` is the table to
  query; what counts as an interesting source is a shop-specific question, so
  consumers own their reports and widgets.
- Also in this release, carried from unreleased commits: the stock-movement
  history modal now renders the body it was pointing at (`adfef28`), and the
  package gained a CI suite of its own — SQLite and MySQL legs (`b450734`).
  Smoke the movement-history modal in both consumers after bumping.


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
