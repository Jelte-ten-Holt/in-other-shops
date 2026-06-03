# Brief: Purchasing / Inbound Inventory domain

**Status:** BUILT package-side (2026-06-03), unreleased. Phase 1 (domain + actions + tests) and Phase 2 (Filament `PurchaseOrderResource`/`SupplierResource` + `PurchasingSchema` discovery picker; `CommerceSchema` discovery retrofit) landed — full suite green (713). **Remaining:** cut a package release, then consumer wiring (in-other-worlds `Product`/`Bundle` adopt `HasPurchases`) — release-gated; plus a live Filament panel smoke check (no render-test harness in the package). Watch the `App\Contracts\Purchasable` (consumer "sellable") vs `HasPurchases` (package "buyable from supplier") naming overlap when wiring.
**Package access:** clear. Brief relocated here from the projects root.
**Scope note:** the build spans **Commerce + Purchasing** (the `CommerceSchema` discovery retrofit), not just the new domain.
**Target package:** `in-other-shops` (new `src/Purchasing/` domain).
**First consumer:** `in-other-worlds` (live orders → drives the reporting layer). `bianka-shop-one` benefits but is pre-launch.

## Purpose

Track new inventory coming in — **what** was bought, **from whom**, **for how much**, and **when it arrives** — so it can be compared against orders to understand revenue flow (spend vs. revenue, and eventually true margin).

## Decisions (locked)

| Fork | Decision |
|------|----------|
| Workflow shape | **Full purchase-order lifecycle** (Draft → Ordered → Partially Received → Received, + Cancelled). Stock moves on *receive*, not on raising the PO. |
| Costing / reporting | **Model now, report later.** Store unit cost richly enough to support per-unit COGS later; build only aggregate spend-vs-revenue reporting first. No costing engine (FIFO/weighted-avg) yet. |
| Supplier | **First-class `Supplier` entity** (reusable; enables spend-by-supplier, payment terms). |
| Stock coupling | **Auto-increment stock on receipt.** Receiving drops `StockMovement(reason: Received)` referencing the PO line; the level goes up. PO creation touches nothing. |
| Currency / FX | **EUR-only.** No FX/base-currency fields. (Revisit only if foreign-currency buying starts.) |
| Landed cost | **Header shipping/customs fields now.** Allocation across lines into true per-unit cost is deferred (reporting concern). |
| Admin UI | **Package ships Filament Resources** (`PurchaseOrderResource` + `SupplierResource`) — the screens to create POs, add suppliers, and receive items. Standalone-Resource pattern (like Tax/Shipping/Payment). The *reporting* dashboard is separate and stays consumer-side (see Reporting). |
| Product linking | **`purchasable` morph → consumer catalog model implementing `HasPurchases` (extends `HasStock`).** **Contract-discovery, applied to both schemas:** a model declares its roles in one place — its `implements` clause (`class Product implements HasOrders, HasPurchases`) — and each schema walks the morph map (`Relation::morphMap()`) and keeps the classes implementing *its* contract (`is_a($class, …, true)`). No per-schema model map. Requires retrofitting `CommerceSchema` to match (breaking, fine pre-launch — single window). On receipt, follow `purchasable → HasStock → stockItem` to move stock. |
| Input VAT | **Net cost + explicit input VAT, transcribed from the supplier invoice** — deliberately the *opposite* of sales. Sales stores gross (B2C display law) and derives tax; purchasing stores net `unit_cost` + an explicit `input_vat` figure because (a) margin uses net, (b) B2B supplier invoices already state net/VAT/gross separately so transcribing matches the source document and avoids re-derivation rounding, (c) input VAT is a reclaim ledger, not customer-facing. **This asymmetry is intentional, not drift — do not "harmonize" it with the gross-inclusive sales model.** Reuse the shared tax primitives (integer cents, `rateBps`, `TaxCategory` enum) from the VAT/gross work — but NOT its gross-inclusive storage. `input_vat` stays nullable until confirmed against the as-built primitives. |

## How it fits existing conventions

Mirror the Orders domain shape and reuse the Inventory ledger:

- Money = **integer cents**, `Currency` enum, no Money object (matches `Order`/`OrderLine`).
- The `Inventory` domain's `StockMovement` ledger is **append-only** and its `StockMovementReason` enum **already includes `Received`/`Restock`** with a polymorphic `reference`. Receiving inventory uses this existing mechanism — no new movement table.
- Domain layout mirrors `src/Inventory/`: `Models/`, `Actions/`, `Contracts/` (`Has*`), `Concerns/` (`InteractsWith*`), `Enums/`, `Events/`, `Listeners/`, `DTOs/`, `Database/{Migrations,Factories}/`, `config/purchasing.php`, `Purchasing.php` registry, `PurchasingServiceProvider.php`.
- Config-driven model resolution via a `Purchasing::` registry so consumers can override models.
- Ships factories + a `PurchasingLogSubscriber` (purchases are audit-worthy: cost, stock, lifecycle).

## Data model

```
Supplier
  name, contact_email?, default_currency, payment_terms?, notes?

PurchaseOrder (header)
  supplier_id (FK)
  reference (string, unique, auto-generated — mirror order_number)
  status (enum: Draft | Ordered | PartiallyReceived | Received | Cancelled)
  currency (Currency, EUR)
  ordered_at?, expected_delivery_at?
  shipping_cost (int cents, default 0)
  customs_cost  (int cents, default 0)
  subtotal / total (int cents)        -- derived from lines + header costs
  notes?
  timestamps

PurchaseOrderLine
  purchase_order_id (FK)
  purchasable (morph → consumer catalog model implementing HasPurchases)
                                            -- morph, not direct FK, per domain-extractability convention
                                            -- in-other-worlds Product / bianka Variant
  sku, description (string snapshots at purchase time)
  quantity_ordered (uint)
  quantity_received (uint, cached sum of receipts — ledger is source of truth)
  unit_cost (int cents, NET)
  input_vat (int cents, NULLABLE — transcribed from supplier invoice; net cost is unit_cost, not gross-derived)
  line_cost (int cents, = unit_cost * quantity_ordered)
  timestamps
```

**No separate `PurchaseReceipt` table** (novel-ish call — flag if disagreed): each receiving event is a `StockMovement(reason: Received)` row that references the `PurchaseOrderLine` and carries its own timestamp, so the ledger *is* the receipt history. `quantity_received` is a cached aggregate kept consistent by the receive action.

## Lifecycle & actions

- `CreatePurchaseOrder(Supplier, PurchaseOrderData)` → Draft.
- Confirm/place → `Ordered` (sets `ordered_at`).
- `ReceiveItems(PurchaseOrder, lineQuantities)` → for each line, drop `StockMovement(Received)` referencing the line, bump `quantity_received` + stock level. Recompute status: any received < ordered ⇒ `PartiallyReceived`; all received ⇒ `Received`.
- `CancelPurchaseOrder` → `Cancelled`. **Cancelling a partially-received PO does NOT reverse already-received stock** (the goods physically arrived). Only blocks further receipts.

## Events & logging

- `PurchaseOrderCreated`, `PurchaseOrderPlaced`, `ItemsReceived` (per receipt), `PurchaseOrderCancelled` (and `SupplierCreated` if useful).
- `final readonly` + `Dispatchable`, routed through `PurchasingLogSubscriber` to the audit log channel.
- **Periphery doc:** add to `in-other-shops` periphery.md — new events fired (what fires) and the consumer reporting that depends on the purchase data (what consumers depend on). Update in the same step as the build, per keep-docs-current.

## Admin UI & product linking

This is the **management** surface (create/receive), distinct from the **reporting** surface below.

- **Package ships `PurchaseOrderResource` + `SupplierResource`** (Filament Resources) plus a **`PurchasingSchema`** static-factory (mirroring `CommerceSchema`/`PricingSchema`/`InventorySchema`). The product picker lives in `PurchasingSchema::purchaseLinesRepeater()`.
- **Discovery model (decided): a model declares its roles once, in its `implements` clause; each schema discovers its own.** The consumer writes `class Product implements HasOrders, HasPurchases` — one location listing all of a model's capabilities. `purchaseLinesRepeater()` finds purchasable types at runtime:
  ```php
  $purchasable = [];
  foreach (Relation::morphMap() as $alias => $class) {
      if (is_a($class, HasPurchases::class, true)) {   // class-string check, no instantiation/DB
          $purchasable[$alias] = $class;
      }
  }
  ```
  The morph map is the existing single registry of morph-target models (the consumer maintains it anyway); `is_a(..., true)` is an in-memory reflection check over a handful of aliases, run at form-build time — negligible cost. `CommerceSchema` filters `HasOrders`, Purchasing filters `HasPurchases`, against the *same* map → each gets its correct slice (`Bundle` is `HasOrders` only, so it's in the order picker, not the purchase picker).
- **Retrofit `CommerceSchema` to match (breaking, pre-launch-safe).** It currently takes an explicit map; convert it to the same discovery so both schemas behave identically. As-built mechanics + step-by-step in the *CommerceSchema retrofit* subsection below. Breaking signature change to `orderLinesRepeater()` + its in-other-worlds caller, one release window ([[feedback_package_breaking_changes]]); sweep `docs/periphery.md`.
- **`HasPurchases` contract** (in `src/Purchasing/Contracts/`) **extends `HasStock`** — to be purchasable you must be stockable, guaranteeing the receive path (`purchasable->stockItem()`) resolves. Mirrors `HasOrders`'s real shape, lighter (EUR-only, and cost is typed from the supplier invoice, not pulled from the catalog):
  ```php
  interface HasPurchases extends HasStock
  {
      public static function purchasableTitleColumn(): string;   // 'name' — column plucked for option labels
      /** @return array{description: string, sku: string|null} */
      public function toPurchaseLineData(): array;               // snapshot on select; NO cost (manual)
  }
  ```
  Title is a **column to pluck** (matching how the order picker builds options today), not a computed method — keeps the single-query option load. Search stays Filament `->searchable()->preload()` over labels (low scale), so no search-columns hook.
- **Naming (decided):** `HasOrders` (exists) + `HasPurchases` (new), per the package's documented `Has*` convention — no `*able`, nothing renamed. (`Is…able` was considered for readability and declined — keeping the documented convention.)
- **Concern:** a thin `InteractsWithPurchasing` ships the reverse `purchaseLines()` relation as default behavior (not an empty trait).
- **Row-level filtering (v2):** if within a type only some rows should be purchasable (active/physical only), add a `purchasableQuery()` scope to the contract. Deferred unless needed at build.

## CommerceSchema — as-built + retrofit plan (scanned 2026-06-03)

`src/Commerce/Filament/CommerceSchema.php` + `src/Commerce/Order/Contracts/HasOrders.php`. How the order-line picker works today, and exactly what the discovery flip changes.

**As-built:**
- `orderLinesRepeater(string $relationship='lines', array $orderableModels=[], string $orderableTitleColumn='name', array $currencyOptions=[])`. Orderable selection is *optional* — with `$orderableModels=[]` it's a plain manual line-entry repeater.
- Options: `buildOrderableOptions()` does `$modelClass::query()->pluck($orderableTitleColumn, 'id')` per model and merges into one `id => title` map — the title column is a single uniform param (default `'name'`).
- The Select is `->searchable()->preload()` — Filament preloads the plucked options and searches their **labels** client-side. No per-column DB search.
- On select: `handleOrderableSelected()` sets `orderable_type` to the morph alias, calls `$model->availableCurrencies()`, then `$model->toOrderLineData($currency)` to snapshot description/sku/unit_price into the line.
- `HasOrders` carries exactly two methods — `toOrderLineData(string $currency): array{description, sku, currency, unit_price, …}` and `availableCurrencies(): array<string>`. **No title/search on the contract** (those are the schema param + Filament defaults).

**⚠ Pre-existing bug to fix in the retrofit:** options are keyed by bare `id` and `findOrderableById()` resolves a pick by trying `$modelClass::find($id)` against each model in turn, returning the first hit. With >1 orderable model, integer IDs collide — `buildOrderableOptions` overwrites (`$options[$id]=$title`) and the wrong model can resolve. Harmless for in-other-worlds today (single `Product`) but the `['product','bundle']` example would trip it. **Fix:** key options by `"{alias}:{id}"` (type-qualified) and parse the alias back on select — which also deletes the guess-by-trying `findOrderableById()` loop.

**Retrofit steps (Commerce + Purchasing, one pre-launch window):**
1. Discovery helper — `discover(string $contract): array` = walk `Relation::morphMap()`, keep `is_a($class, $contract, true)`. **Duplicate the ~5 lines per schema** rather than share it — too small to justify a new cross-domain dependency; keeps domains independently extractable.
2. `orderLinesRepeater()` — drop the `$orderableModels` + `$orderableTitleColumn` params; discover `HasOrders` internally; per-model title from a new static `HasOrders::orderableTitleColumn()`. The private helpers already operate on an `$orderableModels` map, so they keep working on the discovered one — the change is contained to the public entrypoint + `buildOrderableOptions` (title source) + the id-collision fix. (`$currencyOptions` is orthogonal to discovery — leave it.)
3. Add `HasOrders::orderableTitleColumn(): string` (mirrors `HasPurchases::purchasableTitleColumn()`).
4. `PurchasingSchema::purchaseLinesRepeater()` is built discovery-first from the start (`HasPurchases`), no params for model selection.
5. Update in-other-worlds' `orderLinesRepeater(...)` caller (drops the args) + sweep the package's and both consumers' `docs/periphery.md`.

**Net consumer wiring per model:** implement the contract(s) + be in the morph map (already required) + one static title-column method. No schema-side lists anywhere.

## Reporting (deferred, consumer-side)

The comparison/analytics surface ("spend vs. revenue") lives in **in-other-worlds** (a dashboard), not the package. The package may expose query scopes/helpers, but the package's job is the purchase **data + actions**, not the report UI. v1 = aggregate spend vs. revenue over a period and per product. Per-unit COGS (weighted-avg/FIFO) is explicitly later — the model carries enough (net unit_cost + product + receipt date via ledger) to compute it when wanted.

## Explicitly out of scope (for now)

- Costing engine (FIFO / weighted-average) and per-order margin.
- FX / multi-currency cost.
- Landed-cost allocation across lines.
- Separate receipt records.
- The **reporting/analytics** dashboard (spend-vs-revenue). *In scope:* the management UI (`PurchaseOrderResource`/`SupplierResource`) — that's how you enter purchases at all.
- Row-level purchasable filtering (`purchasableQuery()`) — v2 unless needed.

## Pre-build re-scan — DONE (2026-06-03)

Verified against the as-built code. Brief assumptions hold; two corrections folded in above.

- **Confirmed:** `StockMovement` append-only (`const UPDATED_AT = null`) + polymorphic `reference`; `StockMovementReason::Received`/`Restock`; `HasStock::stockItem()` morph relation; `AdjustStock(Model&HasStock, int, StockMovementReason, ?description, ?Model $reference, ?source)`; cents + `Currency` enum; `CalculateIncludedTax`/`CalculateTax(int, int $rateInBasisPoints)`; `rate_bps`; `CalculateTotal`→`PriceBreakdown` with per-bracket `taxBreakdown`; Order/OrderLine + `orderable` morph + `order_number`. Inventory domain = the skeleton to mirror; `InventoryLogSubscriber` shows the `LogDispatcher`/channel routing.
- **Correction 1 — TaxCategory.** Actual cases are `PhysicalGoods` / `DigitalServices` (not books/dice), and rates live in a `TaxRate` table keyed `(country_code, tax_category)`, resolved via `ResolveTaxRate` — *not* on the enum. Low impact: we transcribe `input_vat` off the invoice, so no rate resolution needed; store the amount (+ optionally `tax_category` for grouping).
- **Correction 2 — picker.** `CommerceSchema::orderLinesRepeater()` exists and today uses an *explicit consumer-passed model map* (not the morph-map discovery I'd assumed). **Decision (after discussion): adopt contract-discovery for *both* schemas** — a model declares roles via its `implements` clause, each schema walks the morph map and filters its contract. This means retrofitting `CommerceSchema` off its explicit-map signature (breaking, single pre-launch window). One declaration site per model; no per-schema list. See Admin UI section for the mechanism.

## Open items before build

1. Confirm "no separate receipt table; ledger is receipt history" is acceptable.
2. Migrations are not gated per-consumer (run unused tables — per existing convention); confirm still fine.
3. Package all-clear given (sibling is brief-only now). Ready to build on Jelte's go.
