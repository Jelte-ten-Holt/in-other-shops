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
| `is_default` | boolean | fallback when the destination is inside the seller's home jurisdiction without a specific row |
| `timestamps` | | |

Unique constraint on `(country_code, tax_category)`.

### Enums

**`TaxCategory`** — `physical_goods`, `digital_services`. Models implement `HasTaxCategory::taxCategory()` to declare which they belong to. The enum is intentionally short — projects with finer-grained taxonomy add cases here, not in a separate config.

### Contracts

**`HasTaxCategory`** — single-method interface. The model returns its own `TaxCategory`. **No paired trait** — the decision is per-model and there's no useful default behavior to share. (See `CLAUDE.md` Contract + Concern rule.)

### Jurisdictions (config-driven)

A *jurisdiction* groups countries that share a VAT framework (e.g. the EU 27). Defined in `config/tax.php`:

```php
'jurisdictions' => [
    'eu' => [
        'name' => 'European Union',
        'countries' => ['AT', 'BE', /* ... */ 'SE'],
    ],
],
'home_jurisdiction' => 'eu', // the seller operates here
'export_rate' => [
    'rate_bps' => 0,
    'name' => 'Zero-rated export',
],
```

The package ships an `eu` jurisdiction populated with all 27 member states. Override or add entries per project (Brexit-style events, DACH, etc.).

`TaxConfig` exposes the lookup helpers used internally:

```php
TaxConfig::homeJurisdiction();              // ?string
TaxConfig::jurisdictionForCountry('FR');    // 'eu' | null
TaxConfig::isInHomeJurisdiction('US');      // bool
TaxConfig::exportRate();                    // ['rate_bps' => 0, 'name' => 'Zero-rated export']
```

### Actions

**`ResolveTaxRate`** — given an `Address` and optional `TaxCategory`, returns the most specific matching `TaxRate`. Lookup order:

1. **Country + category match** wins (category-specific rows preferred over country-wide via `ORDER BY tax_category IS NULL`).
2. **Country-wide row** (no category).
3. **No country match + `home_jurisdiction` set:**
    - If destination is **inside** the home jurisdiction → `is_default` row (the seller's home rate, sub-OSS treatment for cross-border EU sales below the €10k threshold).
    - If destination is **outside** the home jurisdiction → synthetic in-memory `TaxRate` with `rate_bps` and `name` taken from `tax.export_rate`. Default: `0` bps, `"Zero-rated export"`. Goods exported outside the EU are zero-rated; the customer's import VAT is their own jurisdiction's concern.
4. **No country match + no `home_jurisdiction` set:** legacy fallback to `is_default` (any unmapped country becomes a global catch-all). Preserves pre-jurisdiction behavior so existing shops don't change behavior on upgrade.

The synthetic export row is **not persisted** — `Commerce/CreateOrder` reads `rate_bps` and `country_code` into a `TaxSnapshot` and the order stores it that way. The audit trail records the actual zero-rate that was applied to that order.

Order-time tax is snapshotted by `Commerce/CreateOrder` into a `TaxSnapshot` so the order persists `(rate_bps, country_code)` independent of future edits or config changes.

### Cross-border modes (no separate setting needed)

How you populate `TaxRate` rows decides which EU VAT mode you operate in:

| Mode | Setup | Behavior |
|---|---|---|
| Sub-OSS (≤ €10k cross-border / year) | Only the home country has a row, marked `is_default`. | EU customers pay home rate; non-EU pays `export_rate`. |
| OSS / destination | Each EU country has its own row. | EU customers pay destination rate; non-EU pays `export_rate`. |

No code switch — just data entry.

## Filament

Standalone admin Resource at `Filament/Resources/TaxRateResource/`. **No `TaxSchema.php`** — the domain has no field-fragments to embed in consuming-project models.

## Configuration

`config/tax.php` exposes:

- `models.tax_rate` — registry override for `TaxRate` extension.
- `jurisdictions` — country groupings (EU shipped by default).
- `home_jurisdiction` — the seller's jurisdiction; null preserves legacy default-as-catchall behavior.
- `export_rate` — synthetic rate applied to destinations outside the home jurisdiction.

## Dependencies

- `Location` — for the `Address` argument to `ResolveTaxRate`.

## Future

The action signature stays positional/named-arg friendly so optional parameters can land non-breakingly:

- **B2B reverse charge:** add a `?bool $businessTaxIdValidated = false` parameter; resolution branches to a synthetic 0% reverse-charge row when an EU buyer's VAT ID is verified (VIES) and the seller is in a different EU country. Will require a VAT ID field on the customer/address and an external validation step — out of scope until you have B2B customers.
- **Per-order place-of-supply override:** for digital services, the place of supply is always the customer's location regardless of `home_jurisdiction`. The category-specific row mechanism already handles this; richer rules can extend the lookup with a digital-specific branch.
