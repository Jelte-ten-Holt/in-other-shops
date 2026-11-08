# Variants Domain

Options and their values: a global catalog of axes (Metal, Ring Size) and the
values on each (Silver, Size 7). bianka-shop-one builds its product Families on
them; in-other-worlds does not use the domain.

> **The `Variant` half is gone (2026-11, complexity cleanup release 2, PR 4).**
> Neither consumer sold a variant: bianka sells each colour as its own product
> grouped into a Family, and in-other-worlds sells flat products. The `Variant`
> model, `HasVariants`/`InteractsWithVariants`, the four variant actions,
> `VariantsSchema`, the `variant` morph alias and the `variants`,
> `option_value_variant` and `optionables` tables were removed; one forward
> migration drops the tables (see below). [`docs/variants-design.md`](../../docs/variants-design.md)
> records the original design.

## Models

### `Option` — an axis (global catalog)

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

An OptionValue may also carry a single **swatch** image (`HasMedia`, `swatch`
collection — `$value->swatch()`) for a visual storefront picker. Set per value
in `OptionResource`; no migration (polymorphic `mediables`).

## Morph map

The service provider registers the morph aliases `option` and `option_value`.
There is no model registry: reference `Option::class` and `OptionValue::class`
directly (the `Variants::option()` / `::optionValue()` accessors and the
`variants.models.*` keys went with the Variant model; no consumer ever
overrode them).

## The drop migration

`2026_11_08_000002_drop_variant_tables` runs once in every consumer. It refuses
to run, with `VariantStillReferencedException`, while a cart line or an order
line still points at a `variant`: the alias is gone after this release and the
package requires a morph map, so such a row could no longer load. Otherwise it
deletes the rows a variant owned (prices, stock items with their movements and
reservations, media links, translations, category and tag links), then drops
`option_value_variant`, `optionables` and `variants`, pivots first. Every step
is guarded, so a run that stopped half-way finishes on the next boot.

## Dependencies

Media and Translation. Variants sits in the integration tier by history; with
the Variant half gone it is a small catalog domain.

## Filament

- `OptionResource` — standalone admin for the global Option/OptionValue catalog
  (translated name + per-value labels; manual-sync values repeater).
  Consumer-registered, like the other package resources.
