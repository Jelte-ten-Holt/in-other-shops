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

## Future

- Country list enum or config (currently free-text `country_code`)
- Address validation / geocoding integration
