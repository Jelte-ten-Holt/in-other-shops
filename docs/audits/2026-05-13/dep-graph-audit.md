# Dependency Graph Audit — 2026-05-13

Audit motivated by the question "is it worth splitting in-other-shops into per-domain Composer packages before bianka-shop-one starts?" The answer was *not yet* (both consumers pre-launch, Bianka uses nearly every domain). But before split pressure is real, the documented dependency graph in [CLAUDE.md:17-34](../../../CLAUDE.md) should match what the code actually does — otherwise drift hardens and the eventual split becomes more expensive.

## Method

For every domain under `src/`, grep `^use InOtherShops\` to enumerate cross-domain imports, then classify each by coupling type:

- **Registry call** — `OtherDomain::model()` resolution. Loose; consumers can swap concrete classes.
- **Contract** — interface from another domain. Loose; permits `instanceof` checks and typed parameters without binding to a concrete class.
- **Event class** — past-tense domain event. Loose; subscribers attach via Laravel's event dispatcher.
- **Concrete model** — `use Other\Models\X`. Tight; binds to a class the other domain owns.
- **Concrete action / DTO / service** — `use Other\Actions\X` injected via constructor. Tight; the calling domain knows the callee exists.
- **Filament Schema / RelationManager** — UI fragment. Tight at the admin layer, loose at the domain layer.

## Documented graph (from CLAUDE.md:17-34)

```
Currency ─────── (independent, foundational)
Translation ──── (independent, foundational)
Logging ──────── (independent)
Location ─────── (independent)
Media ────────── (independent)
Inventory ────── (independent)
Shipping ─────── (independent)
FlowChain ────── (independent)
Pricing ──────── depends on Currency
Taxonomy ─────── depends on Translation
Payment ──────── depends on Currency
Tax ──────────── depends on Location
Commerce ─────── depends on Location, Currency, Payment, Shipping, Tax; soft-deps on Inventory
Storefront ───── depends on Currency, Pricing, Taxonomy, Translation, Media, Inventory
```

(Agent is not in the documented graph at all.)

## Actual graph

Hard deps (concrete classes, actions, DTOs, models) — these would block a split if the consuming domain were extracted.

Soft deps (contracts, events, registries) — these are interface-level, survive a split with no code change.

| Domain     | Hard deps                                          | Soft deps                                                                    | Status |
|------------|----------------------------------------------------|------------------------------------------------------------------------------|--------|
| Currency   | —                                                  | —                                                                            | ✓ matches doc |
| Translation| —                                                  | —                                                                            | ✓ matches doc |
| Logging    | —                                                  | —                                                                            | ✓ matches doc |
| Location   | —                                                  | —                                                                            | ✓ matches doc |
| Media      | —                                                  | —                                                                            | ✓ matches doc |
| FlowChain  | —                                                  | Logging (subscriber)                                                         | ✓ — Logging dep universal; see §"Logging is everywhere" |
| Inventory  | —                                                  | Logging (subscriber), **Translation** (`HasLocaleGroup` instanceof check)    | ⚠ Translation undocumented |
| Pricing    | —                                                  | Currency, Logging                                                            | ✓ matches doc |
| Taxonomy   | Translation (`InteractsWithTranslations` trait, Filament `TranslationSchema`) | Translation contract                                       | ✓ matches doc |
| Payment    | —                                                  | Currency, Logging                                                            | ✓ matches doc |
| Tax        | Location (`Models\Address` typehint)               | —                                                                            | ✓ matches doc |
| Shipping   | **Commerce** (`Commerce::orderLine()` registry, `OrderLine` typehint), Currency, Location | Logging                                          | ⚠ Commerce undocumented — **creates cycle** |
| Commerce   | Inventory (`InsufficientStockException` throw), Location (`Address` model, `LocationSchema`), Payment (`PaymentsRelationManager`), **Pricing** (`ApplyVoucher` action injected into `CreateOrder`, `PriceBreakdown` DTO), Shipping (`CreateShipment` action, `ShipmentsRelationManager`, `ShippingConfig`) | Currency, Inventory contracts, Payment contracts, Shipping contracts, Tax contract, Logging | ⚠ Pricing undocumented; Inventory hardened from "soft" to including a thrown exception type |
| Storefront | —                                                  | Currency, Inventory, Media, Pricing, Taxonomy, Translation, Logging          | ✓ matches doc |
| Agent      | Commerce, Inventory actions (`AdjustStock`), Storefront actions (`ListBrowsables`, `ShowBrowsable`), Storefront resources, Taxonomy registry | Logging, Inventory contracts, Storefront contracts | ⚠ Agent not in documented graph at all |

## Findings, ranked by structural severity

### 1. Shipping ↔ Commerce cycle — blocking for any eventual split

`src/Shipping/Models/ShipmentItem.php:40` resolves the order-line FK via `Commerce::orderLine()`, and `src/Shipping/Actions/CreateShipment.php:22` typehints `Collection<int, OrderLine>` (docblock-only, but the `use` is there). Commerce in turn injects Shipping's `CreateShipment` action and embeds `ShipmentsRelationManager` in Filament. Composer cannot resolve a cycle between two packages — if these were split today, one would have to absorb the other.

The domain-modeling root cause: `ShipmentItem` is currently *specifically* about shipping order lines. But shipping is conceptually broader — you could ship a free sample, a manual restock returning to warehouse, a B2B sample, none of which are order lines. The fix shape is:

- Make `ShipmentItem` polymorphic on a `shippable_type` / `shippable_id` pair (or a `Shippable` contract that `OrderLine` implements).
- Drop the `OrderLine` typehint from `CreateShipment` in favor of `Collection<int, Shippable>` or `Collection<int, Model>`.
- Commerce wires "OrderLine is shippable" on its side; Shipping no longer imports Commerce.

This is a real refactor (migration to add polymorphic columns, contract definition, callers update). Not week-of-work but not an afternoon either. Defer until either the split is on the table or a use case appears that needs non-order shipping.

### 2. Inventory → Translation drift — small but exposes a category problem

`src/Inventory/Actions/AdjustStock.php:68` does an `instanceof HasLocaleGroup` check to decide whether to fan out a stock adjustment across LocaleGroup siblings (when `shares_inventory=true`). This is a single soft check, but it makes Inventory aware of Translation's concepts, which the doc says shouldn't happen.

This is the only Translation reference inside Inventory. The category problem it exposes: the "atomically adjust stock across linked entities" concept is broader than locale groups — it's "shared-inventory siblings." Future cases might be color variants of the same garment, or warehouse-mirrored stockables.

Fix options, smallest to largest:

- **Smallest:** define a `SharesInventory` contract in Inventory; `HasLocaleGroup` extends or aliases it; the check becomes `instanceof SharesInventory`. The Translation import disappears.
- **Medium:** lift the sibling resolution out of Inventory entirely. `AdjustStock` adjusts only `$stockable`; the consumer (or a Translation-side listener on `StockAdjusted`) fans out to siblings. Cost: an extra event round-trip per locale-shared adjust.
- **Largest:** do the medium fix but also make sibling-fanout a configurable strategy in Inventory's config so Variants can register a different fanout strategy later.

The smallest fix is right-sized. Do it when touching `AdjustStock` next.

### 3. Commerce → Pricing — undocumented but correct

Commerce's `CreateOrder` constructor-injects `ApplyVoucher` and accepts `PriceBreakdown` as method input. This isn't drift in the sense of "wrong" — `CreateOrder` *should* know how to commit voucher usage and consume a priced breakdown. It's drift in the sense of "the doc doesn't say it." Pricing is core to Commerce's job.

Fix: add `Pricing` to Commerce's deps in CLAUDE.md. No code change.

### 4. Commerce → Inventory hardened — half-soft, half-hard

CLAUDE.md describes the Inventory dep as soft via `HasStock`. That's still true for the cart guard, but `CreateOrder` also catches/rethrows `InsufficientStockException`. Throwing an exception type from another domain is a tighter coupling than an interface check — Commerce now needs Inventory's exception class to exist at runtime.

Fix: tighten the documented description from "soft-deps on Inventory (cart stock guard, opt-in via HasStock)" to "depends on Inventory contracts and exception types; no concrete model imports."

### 5. Agent isn't in the dep graph

Agent (MCP server) consumes Commerce, Inventory, Storefront, and Taxonomy. It's the integration layer at the top of the graph — every other domain is a leaf relative to it. The doc graph just doesn't mention Agent. Fix: add it as a row, classified as "integration; depends on everything it exposes — not extractable as a leaf."

## Logging is everywhere — noted, not drift

`Logging\DTOs\LogEntry`, `Logging\Enums\LogLevel`, and `Logging\LogDispatcher` are imported by every audit-relevant domain (Commerce, FlowChain, Inventory, Payment, Pricing, Shipping; Agent also uses them). This isn't undocumented drift — it's how log subscribers work, deliberately. But the doc says "Logging — (independent)" without noting the inverse: Logging is *consumed by* nearly every other domain. Worth a one-liner clarification in CLAUDE.md.

If the package ever splits, Logging needs to be one of the first published packages because half the others depend on it.

## Recommended order of operations

Document-only (cheap):

1. Update CLAUDE.md graph to current reality: add Pricing to Commerce, add Translation to Inventory (marked drift), add Commerce to Shipping (marked drift), add Agent row, clarify Logging's universal-consumer pattern.

Small refactor (1–2 hours):

2. Add `SharesInventory` contract in Inventory, have `HasLocaleGroup` extend it, change the `instanceof` check. Removes the Inventory→Translation drift in isolation.

Real refactor (defer until a use case demands it):

3. Polymorphic `ShipmentItem` + `Shippable` contract, decoupling Shipping from Commerce. Don't do this speculatively — it adds polymorphism that isn't currently needed. Trigger to pick up: (a) the package split moves from "eventual" to "this quarter," or (b) a non-order shipment use case appears (warehouse transfer, B2B sample, returns processing).

## Out of scope for this audit

- Whether Commerce should be sub-split into `Cart` / `Order` / `Customer` packages (CLAUDE.md flags this as a possibility; not driven by current need).
- Whether `Storefront` should split admin Filament resources from the read API (deferred per TODO.md "Filament suggest split").
- Variants domain's prospective deps — design exists at [docs/variants-design.md](../../variants-design.md) but Bianka hasn't confirmed she needs variants yet. Audit when the build starts.
