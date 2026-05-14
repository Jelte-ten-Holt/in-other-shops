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
| `compare_at_until` | datetime, nullable | when the strikethrough window closes |
| `minimum_quantity` | integer | quantity tier threshold (default 1) |
| `timestamps` | | |

Unique constraint on `[priceable_type, priceable_id, price_list_id, currency, minimum_quantity]`.

**Strikethrough invariant:** `Price` rejects a `compare_at_amount` that is not strictly greater than `amount` (`InvalidCompareAtPriceException`) — a strikethrough is only a discount if it sits above the actual price. Enforced on the model, so every write path is covered.

**Strikethrough expiry:** set `compare_at_until` to schedule the end of a strikethrough window. The `pricing:expire-compare-at` command (scheduled hourly) promotes `compare_at_amount` to `amount`, clears the strikethrough, and dispatches `CompareAtPriceExpired`.

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

- **`CreatePrice` / `UpdatePrice`** — take the priceable (or `Price`) plus a `PriceData` DTO; persist and dispatch `PriceCreated` / `PriceUpdated`.
- **`ResolvePrice`** — finds the best matching price for a priceable, currency, quantity, and optional price list. Falls back from specific price list to default.
- **`ExpireCompareAtPrices`** — promotes every price whose `compare_at_until` has passed: `compare_at_amount` becomes `amount`, the strikethrough is cleared, `CompareAtPriceExpired` is dispatched. Returns the affected prices. Driven by the `pricing:expire-compare-at` command; rows with a date but no `compare_at_amount` are skipped.
- **`CalculateVoucherDiscount`** — pure calculation. Validates a voucher code (existence, active, expiry, minimum order, currency match) and returns the discount amount. **Does not record usage.** Safe to call repeatedly (cart total displays, checkout review).
- **`ApplyVoucher`** — records a voucher use. Acquires `SELECT ... FOR UPDATE` on the voucher row, re-validates, and increments `times_used` atomically. Throws on race-loss or invalid voucher. Returns the locked `Voucher`. Dispatches `VoucherApplied`. Call at order-commit time inside the same outer transaction as the order-creation action so a failed order rolls back the increment too.
- **`CalculateTax`** — calculates tax in basis points (default 2100 = 21%).
- **`CalculateTotal`** — orchestrates the full price breakdown: builds line items, applies voucher discount via `CalculateVoucherDiscount` (no usage recorded — that's the order-commit's job), computes tax, returns a `PriceBreakdown` DTO.

### Events

- **`PriceCreated` / `PriceUpdated` / `PriceDeleted`** — price CRUD.
- **`CompareAtPriceExpired`** — fired after `ExpireCompareAtPrices` promotes a price; carries the post-promotion `Price` and the `previousAmount` it sold at during the window.
- **`VoucherApplied`** — fired after `ApplyVoucher` increments usage successfully (after the transaction commits).

### DTOs

- **`PriceData`** — readonly input for `CreatePrice` / `UpdatePrice`: `amount`, `currency`, `compareAtAmount`, `compareAtUntil`, `priceListId`, `minimumQuantity`.
- **`PriceBreakdown`** — readonly: `subtotal`, `discount`, `tax`, `total` (all cents), `currency`, `lines` (array of `PriceBreakdownLine`), `voucherCode`.
- **`PriceBreakdownLine`** — readonly: `description`, `unitPrice`, `quantity`, `lineTotal`.

### Filament Integration

**`PricingSchema`** — reusable form components. Per-field factories are the single source of truth, so `priceRepeater()` and `PricesRelationManager` render identically:

- `priceRepeater(relationship)` — a Repeater bound to the `prices` relationship, composed from the field factories below
- `currencySelect(name)` — a currency Select that auto-hides when only one currency is enabled
- `amountField()` / `compareAtAmountField()` — money inputs shown in major units (euros/pounds), dehydrated to integer cents
- `compareAtUntilField()` — strikethrough end date, visible only while a strikethrough is set
- `priceListSelect()` / `minimumQuantityField()`
- `compareAtAmountRule(?Model $record)` — Guard B as a standalone, unit-testable closure: blocks a strikethrough on create (no price history) and rejects one above the price already on record. A heuristic, so it lives in the form layer, not on the model.

The `compareAtAmountField()` also carries an Omnibus-Directive warning tooltip.

**`PricesRelationManager`** — full tabbed UI for managing prices on edit pages.

### Configuration

`config/pricing.php`:

```php
'currencies' => null,                 // null = all enabled currencies
'schedule' => ['enabled' => true],    // run pricing:expire-compare-at hourly
```

Publish with `php artisan vendor:publish --tag=pricing-config`.

### Commands

- **`pricing:expire-compare-at`** — runs `ExpireCompareAtPrices`. Scheduled hourly when `pricing.schedule.enabled` is true.

## Dependencies

- **Currency** — uses `Currency` enum for amount formatting and currency selection

## Future

- Integration with Rule domain for dynamic pricing (percentage/fixed discounts based on conditions)
- Price history tracking
