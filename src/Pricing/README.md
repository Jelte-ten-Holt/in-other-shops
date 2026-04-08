# Pricing Domain

Polymorphic pricing for any model. Supports price lists, quantity tiers, vouchers, tax calculation, and full price breakdown computation.

## Architecture

### Price Model

Polymorphic model attached via `morphMany`. Each price belongs to one priceable and optionally to a price list.

**`prices` table:**

| column | type | purpose |
|---|---|---|
| `id` | bigint | PK |
| `priceable_type` | string | morph type |
| `priceable_id` | bigint | morph ID |
| `price_list_id` | FK, nullable | optional price list |
| `currency` | string(3) | ISO 4217 code, cast to `Currency` enum |
| `amount` | integer | price in cents |
| `compare_at_amount` | integer, nullable | strikethrough / original price |
| `minimum_quantity` | integer | quantity tier threshold (default 1) |
| `timestamps` | | |

Unique constraint on `[priceable_type, priceable_id, price_list_id, currency, minimum_quantity]`.

### Price Lists

Groupings for segmented pricing (wholesale, VIP, seasonal).

**`price_lists` table:**

| column | type | purpose |
|---|---|---|
| `id` | bigint | PK |
| `name` | string | |
| `slug` | string, unique | |
| `description` | string, nullable | |
| `is_default` | boolean | |
| `priority` | integer | resolution order |
| `timestamps` | | |

### Vouchers

Discount codes with fixed or percentage amounts.

**`vouchers` table:** `code` (unique), `type` (VoucherType enum: `fixed`/`percentage`), `amount`, `currency` (nullable — null means any currency), `minimum_order_amount`, `max_uses`, `times_used`, `valid_from`, `valid_until`, `is_active`.

### Contract & Trait

```php
interface HasPrices
{
    public function prices(): MorphMany;
    public function priceFor(Currency $currency, ?PriceList $priceList = null): ?Price;
    public function priceCurrencies(): array;
}
```

`InteractsWithPrices` trait implements all methods. `priceFor()` delegates to the `ResolvePrice` action.

### Actions

- **`ResolvePrice`** — finds the best matching price for a priceable, currency, quantity, and optional price list. Falls back from specific price list to default.
- **`ApplyVoucher`** — validates a voucher code (existence, active, expiry, minimum order, currency match) and returns the discount amount.
- **`CalculateTax`** — calculates tax in basis points (default 2100 = 21%).
- **`CalculateTotal`** — orchestrates the full price breakdown: builds line items, applies voucher discount, computes tax, returns a `PriceBreakdown` DTO.

### DTOs

- **`PriceBreakdown`** — readonly: `subtotal`, `discount`, `tax`, `total` (all cents), `currency`, `lines` (array of `PriceBreakdownLine`), `voucherCode`.
- **`PriceBreakdownLine`** — readonly: `description`, `unitPrice`, `quantity`, `lineTotal`.

### Filament Integration

**`PricingSchema`** — reusable form components:

- `priceRepeater(relationship)` — returns a Repeater bound to the `prices` relationship with currency, amount, compare-at, price list, and minimum quantity fields
- `currencySelect(name)` — returns a currency Select that auto-hides when only one currency is enabled

**`PricesRelationManager`** — full tabbed UI for managing prices on edit pages.

### Configuration

`config/pricing.php`:

```php
'currencies' => null, // null = all enabled currencies
```

## Dependencies

- **Currency** — uses `Currency` enum for amount formatting and currency selection

## Future

- Integration with Rule domain for dynamic pricing (percentage/fixed discounts based on conditions)
- Price history tracking
