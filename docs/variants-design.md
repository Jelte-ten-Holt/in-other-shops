# Variants — domain design

**Status:** approved for build 2026-06-02. Decisions reached 2026-05-09 (Shopware feature-surface comparison driven by `in-other-worlds`); open questions resolved 2026-06-02 against the `bianka-shop-one` consumer (jewellery — recurring axes Metal / Ring Size / Chain Length / Gemstone). Build is gated on this consumer; `in-other-worlds` stays SKU-flat and does **not** adopt Variants.

This doc is the rebrief: it records the settled decisions, the resolved forks, the dependency-graph impact, the planned periphery, and the phased build plan. Start construction from the Build Plan at the bottom.

**Build status:** Phases 1–2 complete and green (unreleased). Phase 1: models, migrations, contracts/concerns, factories, event classes, morph map. Phase 2: package `Variant` implements `HasCart`; `InteractsWithCart` cart-deletion guard (config-gateable, all cart-ables); `lowestVariantPrice`/stock aggregation on `InteractsWithVariants`; actions `CreateVariant`, `GenerateVariants`, `CreateDefaultVariant`, `DeleteVariant` (events dispatched). Full suite 663/663. Two adjustments from the original plan: **`EnsureDefaultVariant` renamed to `CreateDefaultVariant`** (`Ensure` is reserved for void guards in the verb glossary; this creates a record), and **`Generate` registered as a new cross-domain verb**. Storefront variant surfacing deferred (no consumer browses variants via the Storefront API). Phases 3–5 pending. Docs kept current per phase.

## Decisions

### Domain placement: separate `Variants` domain

Not a sub-namespace of Taxonomy. Variants are purchasable units (price, stock, media, cart-able, order-line-snapshot-able), not classifications. Tags and Categories let you find and group existing things; a Variant *is* a thing.

Reasons summarized:

- **Dependency graph.** Variants needs Pricing + Inventory + Media + Translation + Commerce. Folding into Taxonomy would bloat Taxonomy's foundational dependency closure and break the package's domain-extractability principle.
- **Lifecycle and verb family** match Pricing/Inventory (`Create`, `Generate`, `Resolve`, `Adjust`), not Taxonomy (`Find`, `Attach`, `Detach`).
- **`Has*` contract semantics collapse** if `HasTags` ("can be classified") and `HasVariants` ("can have purchasable children") share a domain.
- **OptionValue is not a Tag.** OptionValue belongs to an Option that owns a closed ordered set of values; Variants reference combinations of OptionValues; faceted nav needs structured queries, not string parsing of TagType values.

### Naming: `Option` + `OptionValue` + `Variant`

- `Option` — the attribute (Metal, Ring Size). Owns an ordered set of values. **Global catalog** (see resolved decisions): defined once, attached to many owners.
- `OptionValue` — a value (Silver, Size 7). Belongs to an Option, ordered by `position`.
- `Variant` — a sellable SKU representing a combination of OptionValues.

"Option" rather than Shopware's "Property" — Property is overloaded (filterable attribute *and* variant source); Option is single-purpose.

### Ownership: `HasVariants` polymorphic on the consumer side, with explicit axis declaration (Model B)

The package does not know its consumers. Variant attaches polymorphically to whatever implements `HasVariants`:

- `Variant.variantable` is `morphTo` (`variantable_type`, `variantable_id`).
- Consumers add `HasVariants` to whichever models own variants (most adopt it on a Product-shaped model).
- **The owner explicitly declares its axes** via an `optionables` link (owner → Option). This is the one piece of state that derivation can't represent: a product's axes must exist *before* any variant does, so the option-first "declare axes → generate variants" admin flow works. (The 2026-05-09 draft argued for pure derivation; resolved 2026-06-02 in favour of explicit declaration — see Resolved decisions.)
- **Which values are in play is derived from the variants**, not a second owner→value table. Variant rows encode the value-combinations that exist; adding a value = tick it and regenerate.
- No public `HasOptions` contract on the owner — the owner→option relation lives inside the Variants domain (the design's legitimate code-surface concern is honoured; only the *data* relation is added).

### Stock: per-Variant; owner stock aggregates when `hasVariants()` is true

`hasVariants(): bool` on `InteractsWithVariants`.

When `Owner::hasVariants() === true`:

- Each Variant carries its own StockItem via the existing polymorphic `HasStock`.
- The owner's own StockItem is unused; admin UI hides owner-level stock.
- `InteractsWithVariants` overrides the owner's `isInStock()` / `stockLevel()` to aggregate over its variants (in-stock = any variant in stock).
- PDP add-to-cart resolves the **selected Variant's** stock; reservation flows through the Variant because the Variant is the cartable — nothing reserves the owner.

When false: today's behaviour unchanged.

### Pricing: per-Variant, with parent price as creation-time template

- Variant has its own polymorphic price entries via `HasPrices`. No fallback semantics — prices are independent in storage.
- New Variants auto-fill with the owner's current price at creation time (`GenerateVariants` copies the template) so the "template" semantic is preserved without manual copy.
- **`ResolvePrice` has no min/"from" helper today** — a new `lowestVariantPrice(Currency)` lands on `InteractsWithVariants`, computing `min()` over the variants' resolved prices.
- Admin "edit owner price" path:
  - Owner without variants — direct update.
  - Owner with variants — cascade modal listing Variants with current prices + checkboxes; admin chooses which to update. No silent global cascade. (Modal is consumer-side Filament UX; the package ships the per-variant price data.)
- Storefront display: owner without variants → single price; owner with variants → "from $X" via `lowestVariantPrice()`, on listing card and PDP.

### URL/canonical: owner slug, optional `?variant=42` deep link

- One canonical URL per owner. Variants get no slugs or sitemap entries — keeps canonicalization clean and hreflang siblings intact.
- Optional `?variant=42` preselects the variant in the PDP picker. No SEO implication — canonical points at the bare URL.

### Cart: Variant becomes the cartable

- `cart_items.cartable_type = 'variant'`, `cartable_id = Variant.id`.
- The package `Variant` implements `HasCart` via Commerce's `InteractsWithCart` (resolved 2026-06-02 — see Resolved decisions). This adds a **Variants → Commerce** dependency.
- The existing `morphs('cartable')` schema + `(cart_id, cartable_type, cartable_id)` unique merge key already support this — **no Commerce migration**.

### Order line: same shape as today

`order_lines.orderable` is nullable-polymorphic with a full snapshot (`description`, `sku`, `currency`, `unit_price`, `quantity`, `line_total`, `tax_category`, `tax_rate_bps`, `tax_amount`). A Variant line snapshots `description` (e.g. "Pendant — Silver, 45cm"). Variant deletion does not break historical orders. **No Commerce migration.**

## Resolved decisions (were "open questions")

| Question | Decision (2026-06-02) | Notes |
|---|---|---|
| Option authoring model | **Explicit declaration (Model B)** — owner declares axes; option-first generate-variants flow | Owner→Option `optionables` link, internal to Variants. No public `HasOptions` contract. |
| Option scope | **Global catalog** — Option defined once, attached to many owners | Confirmed 2026-06-02. Jewellery axes (Metal, Ring Size…) recur heavily; per-owner re-entry would be tedious and drift-prone. Adds an `OptionResource` admin screen + an owner→Option attach step. |
| OptionValue scope / ordering | **Per-Option**, ordered by `position` | Values belong to their Option; free per-Option ordering, no separate table. |
| OptionValue + Option translation | **Both translatable** via `HasTranslations` (column-translation, **not** `HasLocaleGroup`) | `OptionValue.label` and `Option.name`. ⚠ bianka `CLAUDE.md` translatable-fields table lists `OptionValue.label` but **omits `Option.name`** — add the `Option` row when wiring bianka. |
| Variant SKU uniqueness | **System-wide unique** (nullable; unique allows multiple nulls) | Standard for import/export/warehouse. |
| First-variant-on-flat-owner | **Build the default-variant path now** (`EnsureDefaultVariant`) | Enabling variants on a flat owner snapshots its current stock + price into a default Variant — non-destructive. |
| Variant deletion vs. live cart | **Block deletion if a live cart references it** | Implemented as a `deleting` boot-hook guard in Commerce's `InteractsWithCart` (catches Filament bulk delete too), **config-gateable**, applying to **all cartables**. See Cross-consumer impact. |
| Filament integration | Package ships **`VariantsSchema`** (manual-sync `fillFormData`/`saveFormData`) + standalone **`OptionResource`** for the global Option catalog | Matches `PricingSchema` + `VoucherResource` precedents. |

## Dependency-graph impact (flag)

Variants is a new high node, **not a leaf**:

```
Variants ──── depends on Pricing, Inventory, Media, Translation, Commerce (HasCart)
              soft-dep: Storefront calls owner->lowestVariantPrice() via the HasVariants contract
```

No cycle (Commerce does not depend on Variants). Extracting Variants requires all five deps. The `Variants → Commerce` edge is deliberate (the package `Variant` is cart-ready out of the box). **Update the dependency table in `CLAUDE.md` and `README.md` when the domain is registered.**

## Planned periphery (for `docs/periphery.md` at release)

- **Morph aliases:** `option`, `option_value`, `variant` (registered in `VariantsServiceProvider::boot()` via additive `Relation::morphMap`).
- **Contracts / traits:** `HasVariants` + `InteractsWithVariants` (owner-side). Package `Variant` adopts `HasPrices`/`HasStock`/`HasMedia`/`HasCart`; `OptionValue` + `Option` adopt `HasTranslations`.
- **Registry:** `Variants::variant()` / `::option()` / `::optionValue()` → `config('variants.models.*')`.
- **Public actions:** `GenerateVariants`, `CreateVariant`, `EnsureDefaultVariant`, `DeleteVariant`.
- **Events:** `VariantCreated`, `VariantDeleted` (`final readonly`, `Dispatchable`). **No `VariantsLogSubscriber`** — catalog-structure edits are admin activity, deferred until multi-user (matches Media/Taxonomy: events dispatched, no subscriber yet).
- **Filament:** `VariantsSchema` static factories; `OptionResource`.
- **⚠ Behaviour change to an existing actor:** Commerce `InteractsWithCart` gains a `deleting` guard affecting **all cartables in all consumers** — record under both the runtime (model boot hooks) and External-surface sections, and refresh "Last verified".

## Build plan (phased)

Release scope (chosen): **package domain + Filament + storefront helpers, then bianka wiring in the same release window.**

**Phase 1 — Package domain core.** Scaffold per `docs/adding-a-new-domain.md`: `VariantsServiceProvider`, `config/variants.php` (`models` key), `Variants` registry, composer autoload + `extra.laravel.providers`, `README.md`. Migrations: `options`, `option_values`, `variants` (morphs `variantable`, `sku` nullable+unique), `option_value_variant` pivot (one value per option per variant — app-guarded), `optionables` (owner→Option). Models: `Option`, `OptionValue`, `Variant`. Contracts/concerns: `HasVariants` + `InteractsWithVariants`. Factories. Morph map. Events. PHPUnit.

**Phase 2 — Cross-domain wiring (package). ✅ Done.** Owner stock aggregation (`hasVariantInStock`/`variantStockTotal`) + `lowestVariantPrice()` on `InteractsWithVariants` (contract extended, trait-defaulted). Commerce: `InteractsWithCart` cart-deletion `deleting` guard (config-gateable, all cart-ables) + package `Variant` implements `HasCart`. Actions: `CreateVariant` (validates options, copies price template), `GenerateVariants` (cartesian product, skips existing, declares axes), `CreateDefaultVariant` (flat-owner migration, carries price + stock via `AdjustStock`), `DeleteVariant` (cleans owned price/stock/media, guard-protected). Events dispatched. Storefront surfacing **deferred** (no consumer uses the Storefront API for variant catalog; consumers call `lowestVariantPrice()` directly).

**Phase 3 — Filament.** `OptionResource` (global Option + OptionValue management). `VariantsSchema` (declare axes, tick in-play values, generate, per-variant SKU/price/stock/media; manual-sync). Tests.

**Phase 4 — Verify + release.** Docs (`docs/periphery.md`, `CLAUDE.md`, `README.md`) are kept current per-phase, not batched here — by Phase 4 they already reflect the built state; confirm they're in sync. **Cross-consumer verification: run in-other-worlds' suite against the `InteractsWithCart` deleting-guard change** (existing-cartable behaviour change). Cut release; bump bianka.

**Phase 5 — bianka wiring (same window).** `App\Models\Variant extends package Variant implements Purchasable` + `variants.models.variant` config swap + `variant`/`option`/`option_value` morph aliases. `Product` adopts `HasVariants` + `InteractsWithVariants`. `ProductResource` integrates `VariantsSchema` + price-cascade modal. Storefront: PDP variant picker (Vue) + `?variant=` deep link + "from $X" on cards. Cart cartable=variant path (mostly pre-wired per bianka `CLAUDE.md`). `EnsureDefaultVariant` on enabling variants. Tests + manual verify. Add the `Option` row to bianka's translatable-fields table in `CLAUDE.md`.

## Cross-consumer impact

- **`in-other-worlds` (SKU-flat, no Variants):** the only thing that reaches it is the **Commerce `InteractsWithCart` deleting guard** — its cartables (Product, etc.) become undeletable while referenced by a live cart. Config-gateable; verify its suite at Phase 4 and decide whether to leave the guard on or gate it off for that consumer. New morph aliases (`option`/`option_value`/`variant`) and the Variants migrations are additive and inert there (no model adopts `HasVariants`, no rows created).
- **`bianka-shop-one`:** the adopting consumer. Consumer prep below is largely already done.

## Out of scope (defer until a real use case appears)

### Configurable bundles ("OR" slot bundles)

A "pick one of these plus one of these" Bundle is a *configurator*, not a Bundle — a distinct feature in every mature platform. Adding it would multiply pricing/stock/cart-line semantics with no consumer use case today. Build it as a new domain on top of Variants and Bundles if a real pick-your-flavour box appears, not by flagging Bundle.

What *is* free: `bundle_items.purchasable` is polymorphic, so a Bundle can already include specific Variants once consumer bundle pickers add Variant to their morph options.

### Variants with different tax categories

TaxCategory stays on the owner. One-physical-one-digital-variant almost always wants two owners. Re-think only if a real case forces it.

## Consumer prep that's already done

Polymorphic primitives mean a typical consumer needs little scaffolding ahead of the build:

- `cart_items.cartable` is polymorphic with the right merge key — Variant slots in without a migration.
- `order_lines.orderable` is nullable-polymorphic with full snapshot — Variant deletion does not break history.
- `bundle_items.purchasable` is polymorphic — Bundles can already include specific Variants.
- The `Has*` pattern means a consumer `Variant` subclass implementing `Purchasable` inherits `HasPrices`/`HasStock`/`HasMedia`/`HasCart` mechanics.

Consumer-side audits worth running at build time: sweep frontend templates for stray owner-typed references that should read off a `cartable`/`purchasable` shape; confirm guest-cart-claim merge keys use `(cartable_type, cartable_id)`; confirm bundle item pickers route via the polymorphic relation.
