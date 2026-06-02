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

> The package `Variant` is intentionally cart-agnostic at the model level in
> Phase 1. A consumer swaps it via `config('variants.models.variant')` for a
> subclass that adds `HasCart` and its own purchasable role contract. Phase 2
> wires the package model itself to `HasCart` plus the cart-deletion guard.

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

There is deliberately **no public `HasOptions` contract** on the owner — the
owner→option relation is internal to this domain.

## Registry & morph map

Models resolve through `Variants::option()` / `::optionValue()` / `::variant()`
(config `variants.models.*`), so consumers can swap any of them. The service
provider registers the morph aliases `option`, `option_value`, `variant`.

## Dependencies

Pricing, Inventory, Media, Translation (and, from Phase 2, Commerce for the
cart-able variant). Variants is an integration-tier domain, not an extractable
leaf — see the dependency table in the root `CLAUDE.md`.

## Not yet in this domain (later phases)

- **Phase 2:** `GenerateVariants` / `CreateVariant` / `EnsureDefaultVariant` /
  `DeleteVariant` actions; `HasCart` on the package `Variant`; the cart-deletion
  guard in Commerce's `InteractsWithCart`; owner stock aggregation and
  `lowestVariantPrice()` ("from $X"); `VariantCreated` / `VariantDeleted`
  dispatch.
- **Phase 3:** `OptionResource` (manage the global catalog) and `VariantsSchema`
  (attach to consumer resources via the manual-sync convention).
