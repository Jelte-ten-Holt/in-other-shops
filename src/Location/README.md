# Location Domain

Polymorphic addresses for any model. Handles shipping, billing, and combined address types.

## Architecture

### Address Model

Polymorphic model attached via `morphMany`. Each address belongs to one addressable (customer, order, etc.).

**`addresses` table:**

| column | type | purpose |
|---|---|---|
| `id` | bigint | PK |
| `addressable_type` | string | morph type |
| `addressable_id` | bigint | morph ID |
| `type` | string | `shipping`, `billing`, `shipping_and_billing` |
| `first_name` | string | |
| `last_name` | string | |
| `line_1` | string | street address |
| `line_2` | string, nullable | apartment, unit, etc. |
| `city` | string | |
| `state` | string, nullable | |
| `postal_code` | string | |
| `country_code` | string(2) | ISO 3166-1 alpha-2 |
| `phone` | string, nullable | |
| `timestamps` | | |

Composite index on `[addressable_type, addressable_id, type]`.

### Address Types (`AddressType` enum)

- **`shipping`** — delivery address
- **`billing`** — invoice address
- **`shipping_and_billing`** — combined (for simple checkouts)

### Contract & Trait

```php
interface HasAddresses
{
    public function addresses(): MorphMany;
}
```

`InteractsWithAddresses` trait provides the `morphMany` relationship.

### Filament Integration

**`LocationSchema`** — reusable form component:

- `addressRepeater(relationship)` — returns a Repeater bound to the `addresses` relationship with all address fields in a 2-column layout

### Helper Methods

- `fullName()` — returns `"first_name last_name"`
- `oneLine()` — returns comma-separated address string

## Dependencies

None. Location is an independent domain.

## Country names

`InOtherShops\Location\Countries` turns ISO 3166-1 alpha-2 codes into localized
names:

```php
Countries::name('DE', 'es');            // "Alemania"
Countries::options(['NL', 'DE'], 'es'); // [['code'=>'DE','name'=>'Alemania'], ['code'=>'NL','name'=>'Países Bajos']]
```

**No country data ships with this package, in any language.** Names come from
ICU/CLDR via ext-intl, so a consumer adding a locale costs nothing — the
alternative (a shipped name table) would make every consumer's new language a
package release carrying ~250 translations for it.

`options()` sorts by the *localized* name using `Collator`, which is what makes
Spanish put "Bélgica" between "Austria" and "Bulgaria" rather than after both.

Resolution per code: `location.country_names[CODE][locale]` → ICU → the code
itself. That override map is empty by default and exists for the cases where a
shop disagrees with ICU's wording.

**Which** countries to offer is not this domain's business — that is the
consumer's (its shipping zones, usually). `Countries` only names them.

## Future

- Address validation / geocoding integration
- `LocationSchema`'s `country_code` is still a free-text `TextInput`; a select
  needs the shop's destination list, which the package does not have.
