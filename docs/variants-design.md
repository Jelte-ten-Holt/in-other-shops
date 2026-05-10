# Variants — domain design

**Status:** design-only, not on the build list. Captures decisions reached 2026-05-09 during a Shopware feature-surface comparison driven by the `in-other-worlds` consumer. When the `Variants` domain begins construction (currently a placeholder per this package's `TODO.md`), start here.

## Decisions

### Domain placement: separate `Variants` domain

Not a sub-namespace of Taxonomy. Variants are purchasable units (price, stock, media, cart-able, order-line-snapshot-able), not classifications. Tags and Categories let you find and group existing things; a Variant *is* a thing.

Reasons summarized:

- **Dependency graph.** Variants needs Pricing + Inventory + Media + (likely) Tax + Translation. Folding into Taxonomy would bloat Taxonomy's foundational dependency closure and break the package's domain-extractability principle — a future blog-only consumer could no longer extract Taxonomy alone.
- **Lifecycle and verb family** match Pricing/Inventory (`Create`, `Adjust`, `Resolve`, `Reserve`), not Taxonomy (`Find`, `Attach`, `Detach`).
- **`Has*` contract semantics collapse** if `HasTags` ("can be classified") and `HasVariants` ("can have purchasable children") share a domain.
- **OptionValue is not a Tag.** Superficially similar (a string label attached to things), but OptionValue belongs to an Option that owns a closed ordered set of values; Variants reference combinations of OptionValues; faceted nav needs structured queries, not string parsing of TagType values. Even at the OptionValue level — the place where collocation feels closest to working — the structural needs diverge.

### Naming: `Option` + `OptionValue` + `Variant`

- `Option` — the attribute (Color, Size). Owns an ordered set of values.
- `OptionValue` — a value (Red, Medium). Belongs to an Option.
- `Variant` — a sellable SKU representing a combination of OptionValues.

"Option" rather than Shopware's "Property". Shopware's Property is overloaded (filterable attribute *and* variant source); keeping Option singular-purpose is clearer.

### Ownership: `HasVariants` polymorphic on the consumer side

The package does not know its consumers. Variant attaches polymorphically to whatever implements `HasVariants`:

- `Variant.variantable` is `morphTo` (`variantable_type`, `variantable_id`).
- Consumers add `HasVariants` to whichever models should own variants. Most projects will adopt it on a Product-shaped model.
- The owning model itself does not implement `HasOptions` directly. The set of Options used by an owner is derivable from its Variants' OptionValues — `hasManyThrough`-style. Two contracts for one relationship is duplicate state.

### Stock: per-Variant; parent stock disabled when `hasVariants()` is true

`hasVariants(): bool` (not `hasChildren()` — business vocabulary, matches the contract name).

When `Owner::hasVariants() === true`:

- The owner's StockItem is unused; admin UI hides owner-level stock.
- Each Variant carries its own StockItem via the existing polymorphic `HasStock`.
- PDP add-to-cart resolves the selected Variant's stock.

When false: today's behaviour unchanged. The `HasStock` resolution on the owner needs a one-line branch on `hasVariants()`; everything else is automatic.

### Pricing: per-Variant, with parent price as template

- Variant has its own polymorphic price entries via `HasPrices`. No fallback semantics — prices are independent in storage.
- New Variants auto-fill with the parent's current price at creation time so the "template" semantic is preserved without manual copy.
- Admin "edit owner price" path:
  - Owner without variants — direct update.
  - Owner with variants — cascade modal listing Variants with current prices and checkboxes; admin chooses which to update. No silent global cascade.
- Storefront display:
  - Owner without variants — single price.
  - Owner with variants — "from $X" derived as `min()` over the Variants' prices, both on listing card and PDP.

### URL/canonical: parent slug, optional `?variant=42` deep link

- One canonical URL per owner.
- Variants do not get their own slugs or sitemap entries — keeps canonicalization noise down and hreflang siblings clean.
- Optional `?variant=42` query param preselects the variant in the PDP variant picker. Cheap UX win, useful for marketing deep links. No SEO implication — canonical still points to the bare URL.

### Cart: Variant becomes the Purchasable

- `cart_items.cartable_type = 'variant'`, `cartable_id = Variant.id`.
- No owner-with-variant-id-extra-column shape. The Variant is the unit.
- The existing `morphs('cartable')` schema and `(cart_id, cartable_type, cartable_id)` merge key already support this without migration.

### Order line: same shape as today

`order_lines.orderable` is already nullable-polymorphic. A line for a Variant snapshots `description` (e.g. "T-shirt — Red, M"), SKU, price, currency, tax category, tax rate. Variant deletion does not break historical orders.

## Out of scope (defer until a real use case appears)

### Configurable bundles ("OR" slot bundles)

A Bundle that says "pick one of these, plus one of these" is a *configurator*, not a Bundle. Distinct feature in every mature platform — Shopify, Shopware, Magento all separate the two. Adding it would multiply pricing semantics, stock semantics, and cart-line shape, with no consumer use case today. Revisit only if a real pick-your-flavour box appears, and build it as a new domain on top of Variants and Bundles, not by adding flags to Bundle.

What *is* free with the existing schema: Bundles can include specific Variants — a Bundle of "Red T-shirt size M + tote bag" works without any change, because `bundle_items.purchasable` is polymorphic. Filament bundle pickers in consumer projects just need Variant added to their morph options when the Variants domain ships.

### Variants with different tax categories

Today TaxCategory lives on the owner. A scenario where one Variant is "physical" and another is "digital" almost always wants two separate owners. Keep TaxCategory on the owner unless a real case forces a re-think.

## Open questions to resolve before the build

- **OptionValue scope** — global ("Red" once, reused across the catalog) or per-Option ("this Color's Red is its own row, ordered alongside this Color's other values"). Per-Option is the obvious shape — values belong to their Option — but worth pinning down so admin UI knows whether OptionValues are picked from a master list or created inline per Option. Per-Option also gives free per-Option ordering (S, M, L) without a separate ordering table.
- **Variant SKU uniqueness** — system-wide unique, per-owner unique, or no constraint. System-wide is the standard answer (import/export, warehouse integration). Decide before the migration.
- **OptionValue translation** — Color names and size labels need localization ("Red" / "Rot"). Variants therefore depends on Translation. Add the edge to the dependency graph in `CLAUDE.md` when the domain is registered.
- **First-time variant migration on an existing flat owner** — when an existing owner gets its first Variants, its current stock/price should become the template-and-default. Spec: create a "default" Variant carrying the existing stock/price, then let the admin add further Variants. Without this path, adding variants to an existing owner is a destructive operation.
- **Variant deletion vs. live cart contents** — OrderLines are safe (snapshot). Cart items hold a live FK; when a Variant is deleted, the package needs a strategy: silently drop the line, mark the line as unavailable on next read, or block deletion if any cart references it. No-op-and-strand is the worst option.
- **Filament integration shape** — package ships `VariantsSchema` matching the existing `MediaSchema` / `PricingSchema` / `TaxonomySchema` pattern; consumers adopt it via the manual-sync convention.

## Consumer prep that's already done

The polymorphic primitives in `Commerce` and `Storefront` mean a typical consumer needs little scaffolding ahead of the build:

- `cart_items.cartable` is polymorphic with the right merge key — Variant slots in without a migration.
- `order_lines.orderable` is nullable-polymorphic with full snapshot — Variant deletion does not break history.
- `bundle_items.purchasable` is polymorphic — Bundles can already include specific Variants.
- The `Has*` contract pattern means a Variant model that implements `Purchasable` (or whatever a consumer's purchasable role contract is) inherits `HasPrices`, `HasStock`, `HasMedia` mechanics for free.

Consumer-side audits that *are* worth running at build time (not before): sweep frontend templates for stray owner-typed references that should read off a `purchasable`/`cartable` shape; confirm guest-cart-claim merge keys use `(cartable_type, cartable_id)` not `cartable_id` alone; confirm bundle item pickers route via the polymorphic relation.
