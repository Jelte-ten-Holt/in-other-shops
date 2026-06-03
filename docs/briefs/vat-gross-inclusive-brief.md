# Brief: gross-inclusive VAT + per-line tax rates (audit R3 — F8, F9, F10)

> **Status: v2 — round-1 critique incorporated** (tax by rate *bracket* not per line; Pricing decoupled from
> Tax / Option A; consumer breaking changes fixed in-window, bianka TODO). Remaining open items at the bottom.
> Owner: package (`in-other-shops`) primarily, with consumer checkout-step changes. One release window
> (all consumers pre-launch). Affects **both** in-other-worlds and bianka.

## Goal

Make VAT correct and explicit for an EU B2C shop:
- **F8** — stored prices are **gross** (tax-inclusive); net + tax are *derived*, never added on top. The
  price shown on a product page is exactly what the customer is charged.
- **F9** — tax resolves **per line** from each item's tax category (books 7%, dice/maps 19%), not one rate
  for the whole cart.
- **F10** — "no rate for destination" stays an explicit decision (export 0% is intentional and flagged),
  never a silent under-charge.

## Current model (what we're changing)

- `Price.amount` — integer cents, **treated as net**. (`src/Pricing/Database/Migrations/...create_prices_table` — `amount` integer.)
- `CalculateTax(amount, rateBps) = round(amount * rateBps / 10000)` — tax computed *on top*. (`src/Pricing/Actions/CalculateTax.php:11`)
- `CalculateTotal` — `total = subtotal − discount + tax + shippingCost`; tax **added**; takes a **single** `int $taxRate`. (`src/Pricing/Actions/CalculateTotal.php:34,102`)
- `ResolveTaxRate(Address, ?TaxCategory)` — **already** resolves a category-specific row when a category is
  passed (country + null-or-matching category, category row wins). (`src/Tax/Actions/ResolveTaxRate.php:37-52`)
- Consumer `ResolveTaxRateForOrder` calls it **with no category** and stores one `taxRate` on the payload.
  (`app/Actions/Checkout/Steps/ResolveTaxRateForOrder.php:27`)
- Consumer `CalculateTotals` applies that **single** `rate_bps` to the whole subtotal. (`Steps/CalculateTotals.php:40`)
- `CreateOrder::allocateTaxToLines` distributes the single order tax proportionally with floor+remainder (F12/F13).

So the change is two intertwined shifts: **net→gross** and **single-rate→per-line**.

## Target model

Stored `amount` is **gross**. Tax is derived **by rate bracket**, not per line — this matches how VAT is shown on
invoices and reported on returns (grouped by rate, never per item):
- group lines by resolved rate (e.g. a 19% bracket, a 7% bracket); sum gross per bracket (after discount allocation).
- per bracket: `net = round(grossBracket * 10000 / (10000 + rateBps))`; `tax = grossBracket − net`.
- order: `subtotal(gross) − discount + shipping = total`; `tax` = sum of per-bracket tax, a *component* of the gross, not an addend.
- lines keep their own `rateBps` (for bracket grouping and for per-line refund reversal), but **no per-line tax amount** is stored or shown.

Display "just works": the product page already renders the stored `amount` (`formattedAmount()`), which is now
gross — the legal B2C display. The receipt shows the derived net + tax breakdown.

## Proposed design

### 1. Tax mode — per order, defaulting from config (Pricing)
`config/pricing.php` → `'default_tax_mode' => 'inclusive'`. The mode is resolved **per order** (a B2B order could
be `exclusive` while B2C is `inclusive`), passed into `CalculateTotal`, and **snapshotted on the order** so the
breakdown is reproducible. Today every order is `inclusive` (B2C only). The `exclusive` path is **plumbed and
snapshotted but not implemented now** — it's the B2B seam (§8), not built here. Building inclusive fully while
keeping the toggle structurally present is what "baked in" means.

### 2. Tax math (Pricing)
- Add `CalculateIncludedTax(grossAmount, rateBps): int` = `gross − round(gross * 10000 / (10000 + rateBps))`.
  Keep `CalculateTax` (add-on-top) for the `false` mode. (Two small actions, one rounding strategy across both — `round`, half-away-from-zero; pin with boundary tests per the auditor's weak-inputs rule.)

### 3. Per-bracket tax in `CalculateTotal` (Pricing) — **breaking signature change** (Option A, decided)
Each `items[]` entry carries its `rateBps`, **resolved by the caller** — Pricing stays decoupled from Tax: it
receives rates, it never resolves them. `CalculateTotal` groups lines by `rateBps`, derives included tax per
bracket, sums. `PriceBreakdown` gains a `taxBreakdown` summary — a list of `{rateBps, taxableBase, tax}` per rate
(the invoice/VAT-return shape); `PriceBreakdown.tax` is their sum. **No per-line tax fields.** The one true break
is `CalculateTotal`'s signature (single `int $taxRate` → per-item `rateBps`).

### 4. Discount × tax
Discount applies to the gross subtotal (discount-before-tax stays correct). Allocate the cart-level discount
across **brackets** (proportional to each bracket's gross — integer floor + remainder-to-last, at most a couple of
brackets), then derive each bracket's included tax from its discounted gross. Far simpler than per-line allocation,
and it **dissolves F12/F13** — there is no per-line tax back-distribution anymore.

### 5. Consumer checkout steps
- `ResolveTaxRateForOrder` → resolve a rate **per distinct tax category** in the cart (call `ResolveTaxRate`
  with each line's `TaxCategory`), attach `rateBps` to each line (or store a category→rate map on the payload).
  Keep the null-rate throw (F10). Add: if the resolved row is the 0% export row for a destination *inside* the
  home jurisdiction, that's a bug-flag (log/alert) — but plain export stays 0% by design.
- `CalculateTotals` → pass per-line rates into `CalculateTotal` (option A).

### 6. Order persistence
Store a **per-bracket tax summary** on the order (the invoice/VAT-return shape: `{rateBps, taxableBase, tax}` per
rate) — JSON-cast column or a small `order_tax_summaries` table (see Open). Order lines keep their **own**
`rateBps` (needed to compute reversed tax on a per-line refund). Drop `CreateOrder::allocateTaxToLines`'
proportional back-distribution — tax now comes straight from the breakdown's per-bracket figures. Order-level
`tax_rate_country_code` stays. **Expose the summary via an order accessor** (e.g. `$order->taxSummary()`), never by
reading the raw column — so moving JSON→table later is an internal change that breaks no consumer.

### 8. B2B / zero-rate extension seam (design-for, do NOT build now)
Zero-rated and reverse-charge tax (B2B intra-EU, exports) is not built now but must not require a rebuild. The
per-bracket model already handles a 0% bracket natively (`rateBps=0` → `tax=0`), so nothing special-cases
"no tax". Keep two seams open: (a) `ResolveTaxRate` can later take a **customer tax-status** input
(standard / reverse-charge / export) alongside country+category — additive to its signature; (b) the per-order
**tax mode** (§1) is where "charge net, tax handled separately" will live. F10's "silent 0%" concern therefore
becomes a **reconciliation tripwire** — *0% tax on an in-jurisdiction order while the shop is B2C-only* is suspect
— rather than a hard rule, since 0% is legitimate once B2B lands.

### 7. Data / migration
Pre-launch, **test data only** in both consumers — no production prices exist. So **no data migration**: existing
amounts are simply reinterpreted as gross going forward. Seeders should be sanity-checked so seeded amounts read
sensibly as gross. (If any real prices existed we'd need a net→gross backfill = `round(net * (1+rate))`; flag if
that changes before release.)

## Tests (must pin, per the auditor's weak-inputs rule — no all-divisible-evenly inputs)
- gross == net + tax to the cent, at **19% and 7%**, on amounts that land on a `.5` boundary and odd quantities.
- product-page price == amount charged at checkout (the F8 invariant, end-to-end).
- mixed-rate cart: a 7% line + a 19% line → two per-line rates; `order.tax == sum(per-line tax at own rate)`.
- voucher: proportional allocation keeps `sum(line tax) == order.tax`; 0%/no-op voucher changes nothing.
- `prices_include_tax=false` still does add-on-top (the B2B path) — one regression test so the flag is real.
- F10: non-EU destination → 0% export by design (pinned); destination inside jurisdiction with tax==0 → flagged.

## Breaking changes (single release window, all consumers pre-launch)
- `CalculateTotal::__invoke` signature (single rate → per-line). Consumer `CalculateTotals` updated same window.
- `PriceBreakdownLine` gains fields (additive); `PriceBreakdown.tax` semantics change (component, not addend).
- `CreateOrder` line-tax sourcing changes.
- bianka: inherits the corrected model; its checkout (when built) consumes the new signature from day one.

**Back-compat sweep (done):** the only caller of `CalculateTotal` is the consumer's `CalculateTotals` step;
`PriceBreakdown` is consumed only by `CreateOrder` (package-internal) + held as a payload field. All display code
reads the **persisted `order.tax`** (the total), which stays valid under inclusive tax — no add-on-top assumption
in any consumer. bianka has no usage. So the break is **one consumer call site + package-internal `CreateOrder`.**

## Resolved (round-1 critique, Jelte)
- **Tax by bracket, not per item** — group by rate, derive once per bracket; don't store/show tax per line. Also
  settles the rounding point (per-bracket gross, once) and dissolves F12/F13.
- **Option A — Pricing decoupled from Tax** — caller passes resolved `rateBps`; Pricing never resolves tax.
- **Breaking changes** — fix in-other-worlds in the same release window; bianka (no checkout yet) gets a TODO.

## Resolved (round-2, Jelte)
1. **Tax-summary storage → JSON column**, behind an order accessor so a later JSON→table move is internal
   (non-breaking). Filing is rare and the data is all there — extract from orders ad hoc when needed.
2. **B2B / zero-rate → design-for, don't build** (§8). 0% is legitimate for B2B/export, so it isn't hard-flagged;
   it becomes a reconciliation tripwire while the shop is B2C-only.
3. **Tax mode → per order** (not global-only), default `inclusive` from config, snapshotted on the order. Inclusive
   built now; exclusive is the plumbed-but-unbuilt B2B seam.
4. **Back-compat sweep → done** (see Breaking changes): blast radius is one consumer call site + package-internal.

## Implementation progress
- ✅ **Package (steps 2–5) done 2026-06-03**, all green (688 package tests): `TaxMode` + config + `PricingConfig`;
  `CalculateIncludedTax`; per-bracket `CalculateTotal` (decomposed into `sumGrossByBracket` /
  `allocateDiscountAcrossBrackets` / `buildTaxBracket`); `PriceBreakdown`/`PriceBreakdownLine` + `TaxBreakdownLine`;
  `CreateOrder` stores `orders.tax_summary` + per-line `tax_rate_bps`, drops `allocateTaxToLines`; `Order::taxSummary()`;
  migration (tax_summary, tax_amount nullable). Periphery doc updated.
- ⏳ **Next: step 6 (consumer)** — symlink `../in-other-shops`; update `CalculateTotals` (pass per-line rates) +
  `ResolveTaxRateForOrder` (resolve per tax_category); fold in F10 (flag in-jurisdiction zero-tax); run consumer
  suite. Then release + bump + bianka TODO.

Brief is final. Next: set up the temporary `../in-other-shops` symlink, then implement package-first
(config + tax mode → included-tax math → per-bracket `CalculateTotal` + `taxBreakdown` → `CreateOrder` summary),
with boundary-input tests at each step; update in-other-worlds's `CalculateTotals` + `ResolveTaxRateForOrder` in
the same window; add a bianka TODO. Check in after the core math lands.
