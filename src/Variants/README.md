# Variants Domain

Purchasable product variations — a `Variant` is a sellable SKU representing one
combination of option values (e.g. "Pendant — Silver, 45cm"). Variants carry
their own price, stock, and media; the variant becomes the cart-able unit when
an owner has variants.

> **Design rationale** lives in [`docs/variants-design.md`](../../docs/variants-design.md) —
> read it for the decisions behind this domain (separate-domain placement,
> global option catalog, explicit axis declaration, deletion policy, phasing).

## Models

### `Option` — a variant axis (global catalog)

Defined once and reused across the catalog (Metal, Ring Size). Owns an ordered
set of `OptionValue`s. The display `name` is translatable (column-translation
via `HasTranslations`); `slug` is the stable, non-translated identifier.

**`options` table:** `id`, `slug` (unique), `position`, `timestamps`.

### `OptionValue` — a value of an Option

Belongs to one `Option`, ordered by `position` within it (S, M, L). The display
`label` is translatable; `value` is the stable per-Option code (unique with
`option_id`).

**`option_values` table:** `id`, `option_id` (FK, cascade), `value`,
`position`, `timestamps`. Unique on `[option_id, value]`.

### `Variant` — a sellable SKU

Owned polymorphically by a consumer model via `variantable`. Implements
`HasPrices`, `HasStock`, `HasMedia`. SKU is system-wide unique (nullable).

**`variants` table:** `id`, `variantable` morph, `sku` (nullable, unique),
`position`, `timestamps`.

A variant's option values are linked through the `option_value_variant` pivot
(unique on `[variant_id, option_value_id]`; one value per option is enforced in
the variant-creation actions). `optionSummary($locale)` joins the value labels
in option order ("Silver, 45cm"); consumers prefix the owner's name to compose a
full display name.

> The package `Variant` implements `HasCart` — it is the cart-able unit. A
> consumer may still swap it via `config('variants.models.variant')` for a
> subclass that adds its own purchasable role contract on top.

## Ownership — `HasVariants`

Consumer models that own variants implement `HasVariants` and use
`InteractsWithVariants`:

```php
use InOtherShops\Variants\Contracts\HasVariants;
use InOtherShops\Variants\Concerns\InteractsWithVariants;

final class Product extends Model implements HasVariants
{
    use InteractsWithVariants;
}
```

This provides:

- `variants()` — `morphMany`, ordered by `position`.
- `options()` — the **explicitly declared** axes, via the `optionables` pivot
  (`morphToMany`, ordered by pivot `position`). Declaring axes up front is what
  lets the admin define options before any variant exists (the option-first
  "declare axes → generate variants" flow). Which *values* are in play is
  derived from the variants themselves — there is no second owner→value table.
- `hasVariants()` — true once the owner has at least one variant; reads a loaded
  relation without a query when available.
- `lowestVariantPrice($currency)` — the "from $X" price (min across variants).
- `hasVariantInStock()` / `variantStockTotal()` — stock aggregation for an
  owner whose stock lives on its variants. A consumer branches its own
  `isInStock()`/`stockLevel()` on `hasVariants()` to delegate here.

There is deliberately **no public `HasOptions` contract** on the owner — the
owner→option relation is internal to this domain.

## Registry & morph map

Models resolve through `Variants::option()` / `::optionValue()` / `::variant()`
(config `variants.models.*`), so consumers can swap any of them. The service
provider registers the morph aliases `option`, `option_value`, `variant`.

## Actions

- `CreateVariant` — single variant from a set of option values; validates
  one-value-per-option and options-declared-on-owner; copies the owner's price
  template; dispatches `VariantCreated`.
- `GenerateVariants` — declares the axes, then creates the cartesian product of
  the selected values, skipping combinations that already exist (re-runnable).
- `CreateDefaultVariant` — flat-owner migration: one default variant carrying
  the owner's current price and stock (the latter via `AdjustStock`, so it hits
  the audit ledger). No-op when the owner already has variants.
- `DeleteVariant` — deletes the variant and its owned price/stock/media rows;
  the cart-deletion guard fires first (blocks if a live cart references it).

## Dependencies

Commerce (cart-able variant), Pricing, Inventory, Media, Translation. Variants
is an integration-tier domain, not an extractable leaf — see the dependency
table in the root `CLAUDE.md`.

## Not yet in this domain (later phases)

- **Phase 3:** `OptionResource` (manage the global catalog) and `VariantsSchema`
  (attach to consumer resources via the manual-sync convention).
- **Deferred:** Storefront-API variant surfacing ("from $X" in `BrowsableResource`)
  — no consumer browses variants through the Storefront API yet; consumers call
  `lowestVariantPrice()` directly when rendering.
