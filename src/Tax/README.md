# Tax Domain

Country-and-category VAT/sales-tax rates for the order pipeline. Resolves a single applicable rate at order time given a billing address and optional product category, and snapshots the rate onto the order so historical orders are unaffected by later rate edits.

## Architecture

### Models

**`TaxRate`** — one row per `(country_code, tax_category)` combination, plus optional global default rows.

| column | type | purpose |
|---|---|---|
| `id` | bigint | PK |
| `country_code` | string(2), indexed | ISO-3166 alpha-2, uppercased on resolve |
| `tax_category` | string, nullable, indexed | enum value from `TaxCategory` (e.g. `physical_goods`, `digital_services`); `null` = country-wide rate covering everything |
| `rate_bps` | integer | rate in basis points (e.g. `1900` = 19%) |
| `name` | string | human label (e.g. `"VAT 19% (DE)"`) |
| `is_default` | boolean | fallback when no country/category match |
| `timestamps` | | |

Unique constraint on `(country_code, tax_category)`.

### Enums

**`TaxCategory`** — `physical_goods`, `digital_services`. Models implement `HasTaxCategory::taxCategory()` to declare which they belong to. The enum is intentionally short — projects with finer-grained taxonomy add cases here, not in a separate config.

### Contracts

**`HasTaxCategory`** — single-method interface. The model returns its own `TaxCategory`. **No paired trait** — the decision is per-model and there's no useful default behavior to share. (See `CLAUDE.md` Contract + Concern rule.)

### Actions

**`ResolveTaxRate`** — given an `Address` and optional `TaxCategory`, returns the most specific matching `TaxRate`, or the global default, or `null`. Lookup order:

1. `country_code` matches **and** (`tax_category` matches the requested category **or** is null/country-wide). Category-specific rows win over country-wide via `ORDER BY tax_category IS NULL`.
2. Fallback: any `is_default = true` row.
3. Otherwise `null`.

Order-time tax is snapshotted by `Commerce/CreateOrder` into a `TaxSnapshot` so the order persists `(rate_bps, name, country_code, category)` independent of future edits.

## Filament

Standalone admin Resource at `Filament/Resources/TaxRateResource/`. **No `TaxSchema.php`** — the domain has no field-fragments to embed in consuming-project models.

## Configuration

`config/tax.php` exposes:

- `models.tax_rate` — registry override for `TaxRate` extension.

## Dependencies

- `Location` — for the `Address` argument to `ResolveTaxRate`.
