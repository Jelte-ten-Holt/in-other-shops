# Currency Domain

Foundational domain providing typed currency representation, formatting, and configuration. No database tables — purely an enum with utilities.

## Architecture

### Currency Enum

Backed string enum with cases for each supported currency (EUR, USD, GBP).

**Methods:**

- `symbol()` — returns the currency symbol (`€`, `$`, `£`)
- `decimals()` — returns decimal places (all currently 2)
- `format(int $amount)` — formats a cents-based integer into a human-readable string (e.g., `€12.50`)
- `enabled()` — returns only currencies listed in `config('currency.enabled')`, or all cases if unconfigured
- `enabledOptions()` — returns `['EUR' => 'EUR', ...]` for Filament select fields

### Configuration

`config/currency.php`:

```php
'enabled' => ['EUR', 'USD'],
```

Projects restrict which currencies are available by listing ISO 4217 codes. An empty or null array means all cases are available.

## Dependencies

None. Currency is an independent, foundational domain. Both Pricing and Commerce depend on it.

## Future

- Additional currencies as needed (add cases to the enum)
- Locale-aware formatting (thousand separators, decimal separators)
