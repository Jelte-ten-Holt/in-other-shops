# Option Domain

Reusable attribute system for attaching named option groups and values to any model. Designed for product variant differentiation (Size, Color) but also supports descriptive attributes (Material, Warranty) — the distinction is made per-attachment, not per-option.

---

## Why Not Taxonomy?

Options and Taxonomy look similar (both attach labels to models) but serve different purposes:

- **Taxonomy** is organizational — "where do I find this?" Categories for navigation, Tags for discovery/filtering.
- **Options** are structural — "what makes this thing different from that thing?" They define attributes and the axes of variation.

A "Size: 42" option and a "Waterproof" tag are not the same kind of concept. Merging them would blur Taxonomy's responsibility.

---

## Models

### Option (`Models/Option.php`)

A named attribute group.

| Field | Type | Description |
|-------|------|-------------|
| `name` | `string` | Display name ("Color", "Size", "Material") |
| `slug` | `string` (unique) | URL/code-safe identifier |
| `display_type` | `string\|null` | Hint for frontend rendering ("swatch", "dropdown", "buttons") |
| `sort_order` | `integer` | Display ordering |

**Relationships:**

- `values(): HasMany` — the individual values within this group.

### OptionValue (`Models/OptionValue.php`)

A specific value within an option group.

| Field | Type | Description |
|-------|------|-------------|
| `option_id` | `foreignId` | Parent option group |
| `label` | `string` | Display text ("Red", "42", "Cotton") |
| `value` | `string\|null` | Raw/machine value if different from label (hex code for colors, etc.) |
| `sort_order` | `integer` | Display ordering within the group |

**Relationships:**

- `option(): BelongsTo` — the parent option group.

---

## Pivot: `option_valueables`

The polymorphic many-to-many pivot table that attaches option values to any model.

| Column | Type | Description |
|--------|------|-------------|
| `option_value_id` | `foreignId` | The attached option value |
| `option_valueable_type` | `string` | Morph type of the owning model |
| `option_valueable_id` | `unsignedBigInteger` | Morph ID of the owning model |
| `is_variant` | `boolean` | Whether this option value defines a variant axis on this model |

### The `is_variant` flag

This flag lives on the **pivot**, not on the Option or OptionValue, because the same option can be a variant axis for one product type and a descriptive attribute for another:

- **Boot** → Size is `is_variant: true` (Size 42 and Size 43 are distinct purchasables)
- **Hat** → Size is `is_variant: false` (one-size-fits-most, just informational)

The Option domain provides the flag and convenience query methods. The project decides what "variant" means in its context.

---

## Contracts

### `HasOptions`

For any model that carries option values.

```php
interface HasOptions
{
    public function optionValues(): MorphToMany;
}
```

---

## Concerns

### `InteractsWithOptions`

Implements `optionValues(): MorphToMany` and provides convenience methods for filtering by the pivot flag:

```php
trait InteractsWithOptions
{
    public function optionValues(): MorphToMany
    {
        return $this->morphToMany(OptionValue::class, 'option_valueable')
            ->withPivot('is_variant');
    }

    public function variantOptions(): MorphToMany
    {
        return $this->optionValues()->wherePivot('is_variant', true);
    }

    public function descriptiveOptions(): MorphToMany
    {
        return $this->optionValues()->wherePivot('is_variant', false);
    }
}
```

---

## How Variants Work (Project-Level)

The Option domain knows nothing about products, variants, or parent/child relationships. It provides attachable attribute values with a contextual flag. The project wires it up:

### 1. Project defines its variant model

```php
class Variant extends Model implements HasOptions, Purchasable
{
    use InteractsWithOptions;
    use InteractsWithPrices;
    use InteractsWithOrders;

    public function product(): BelongsTo { ... }
}
```

### 2. Variant options are attached with the pivot flag

```php
// Boot variant: Size 42, Color Black
$variant->optionValues()->attach($size42->id, ['is_variant' => true]);
$variant->optionValues()->attach($black->id, ['is_variant' => true]);
$variant->optionValues()->attach($realLeather->id, ['is_variant' => false]);
```

### 3. Project queries use the flag

```php
// Build a variant picker from only the variant-axis options
$variantOptions = $variant->variantOptions;  // Size: 42, Color: Black

// Show descriptive attributes in a specs table
$specs = $variant->descriptiveOptions;  // Material: Real Leather
```

### 4. Frontend groups by variant options

The product detail page loads the parent, eager-loads variants with their option values, and groups them into pickers. The `is_variant` flag tells the frontend which options get a picker (Size, Color) and which go in a details/specs section (Material).

---

## Integration Points

- **Pricing** — prices attach to variants (which carry options), not to options themselves. Option-dependent pricing (XXL costs more) is modeled by the variant having a different price, not by the option carrying a price.
- **Inventory** *(future)* — stock levels track per variant. The variant's option values describe what's in stock ("Size 42 in Red: 3 remaining").
- **Storefront** — the parent product is `HasStorefrontPresence`. Variants are not browsable (they don't appear in catalog listings). The detail page endpoint includes variants with their option values so the frontend can build a picker.
- **Taxonomy** — no direct relationship. Options define "what this is" (attributes), Taxonomy defines "where to find it" (organization). A product can have both categories/tags and option values without any overlap.

---

## File Structure

```
app/Domains/Option/
├── OptionServiceProvider.php
├── Contracts/
│   └── HasOptions.php
├── Concerns/
│   └── InteractsWithOptions.php
├── Models/
│   ├── Option.php
│   └── OptionValue.php
├── Database/
│   └── Migrations/
│       ├── create_options_table.php
│       ├── create_option_values_table.php
│       └── create_option_valueables_table.php
├── Filament/
│   └── OptionSchema.php
└── README.md
```
