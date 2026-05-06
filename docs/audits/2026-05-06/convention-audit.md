# Convention Audit — 2026-05-06

Second pass. The first audit ([2026-04-22](../2026-04-22/convention-audit.md)) was never committed and none of its findings were acted on; recurring items below carry a `(recurs from 2026-04-22)` tag so the user can decide whether to settle them or note them as deliberately deferred.

## Audit target
`/home/jelte/projects/in-other-shops` — Laravel 12+ multi-domain package. 15 domains under `src/` (Tax added since last audit), ~40 invokable Actions, 37 feature tests, dual `CLAUDE.md` + per-domain READMEs.

## Documented conventions (reference, not findings)
- Architecture: domains independently extractable, dependency graph in `CLAUDE.md:17-33`, no cross-domain FKs.
- Naming: `Has*` capability contracts + `InteractsWith*` traits (`CLAUDE.md:57-58`).
- Registry pattern, config-driven models, factories ship with domain (`CLAUDE.md:39-46`).
- Models non-final, `$guarded = []`, method-`casts()`, morph aliases (`CLAUDE.md:67`).
- Actions: invokable, stateless, single-responsibility, string-backed enums.
- Filament: ship `{Domain}Schema.php`, dispatch past-tense events, log subscribers per domain.
- Agent tools: `ok/target/data/error` envelope; throw vs `ok:false` rules in `docs/agent-tool-conventions.md`.
- FlowChain: steps throw, transaction wraps run.

## Accidental conventions

### [mixed] HTTP layer placement — three shapes coexist *(recurs from 2026-04-22)*
Occurrences: `Http/Controllers/` (Commerce/Cart, Agent), top-level `Controllers/` (Storefront), no Http at all (Commerce/Order, Commerce/Customer — actions only).

Examples:
- `src/Commerce/Cart/Http/Controllers/CartController.php` — sub-namespaced under `Http/`, with `Http/Requests/`, `Http/Resources/`, `Http/Support/` siblings
- `src/Agent/Http/Controllers/AuthorizationServerMetadataController.php` — domain-level `Http/`
- `src/Storefront/Controllers/CategoryShowController.php` — top-level, with peer `Resources/`, `Middleware/`, `Routes/` directly under domain root

Documented? No.
Plausible alternative: pick one — every domain's HTTP-facing pieces live under `{Domain}/Http/...`, or every domain hangs `Controllers/`/`Resources/`/`Routes/` at its root.
Suggested action: **document as-is** — when Commerce/Order or Commerce/Customer get their first controller, the next contributor has no rule to follow. Two weeks of inertia hasn't picked one; pick one.

### [implicit] Action input shape — always positional, never input DTOs
Occurrences: ~40 actions, all positional. Many with 6–9 parameters; outputs sometimes use DTOs.

Examples:
- `src/Payment/Actions/InitiatePayment.php:23-32` — 9 positional params (`gatewayName, payable, amount, currency, returnUrl, cancelUrl, metadata, profileable, customerData`), returns `InitiatePaymentResult` DTO
- `src/Commerce/Order/Actions/CreateOrder.php:24-31` — 8 positional including `array $billingAddress` (untyped)
- `src/Inventory/Actions/AdjustStock.php:22-29` — 6 positional
- `src/Pricing/Actions/CalculateTotal.php` — 6 positional, returns `PriceBreakdown` DTO

Documented? No (CLAUDE.md just says "invokable, stateless, single-responsibility").
Plausible alternative: input DTOs (`InitiatePaymentRequest`), or named-args-only calling with structured option objects for the long tails.
Suggested action: **discuss** — input/output asymmetry is real. Status quo works but every new optional param forces every callsite to pass through positional defaults; `array $billingAddress` is untyped by choice.

### [implicit] All non-FlowChain throws are SPL exceptions *(recurs from 2026-04-22)*
Occurrences: 28× `InvalidArgumentException`, 3× `RuntimeException`, 1× `DomainException`, 0 domain-specific exception classes outside FlowChain (whose exceptions are internal flow-control, not user-facing).

Examples:
- `src/Pricing/Actions/CalculateVoucherDiscount.php:36-53` — "Voucher not found", "Voucher is no longer valid", "currency does not match" all throw `InvalidArgumentException` (these are domain-rule rejections, not bad arguments)
- `src/Payment/Actions/RefundPayment.php:42-61` — refund-amount-exceeds-remaining throws `InvalidArgumentException`
- `src/Inventory/Actions/AdjustStock.php:178-180` — invalid `source` throws `InvalidArgumentException` (this one IS arg validation, fine)

Documented? Partially — `docs/agent-tool-conventions.md:117-133` says caller bugs throw, business rule rejections return `ok:false`. But that rule applies at the agent layer; the underlying domain actions throw `InvalidArgumentException` for both classes indistinguishably.
Plausible alternative: typed exceptions (`VoucherInvalidException`, `RefundAmountExceededException`, or per-domain `{Domain}Exception` base) so callers can branch; `LogicException` family for invariant violations vs `InvalidArgumentException` for actual bad input.
Suggested action: **discuss** — once consumers (in-other-worlds) start catching specific business outcomes, "everything is `InvalidArgumentException`" forces string-matching error messages.

### [implicit] Commerce uses sub-namespaced sub-domains; nothing else does
Occurrences: 1 domain with sub-namespacing (Commerce → Cart/Customer/Order, each with full Actions/Concerns/Contracts/Enums/Models/Events tree). 14 domains flat.

Examples:
- `src/Commerce/Cart/Actions/`, `src/Commerce/Order/Actions/`, `src/Commerce/Customer/Actions/` — three peer trees inside Commerce
- `src/Inventory/Actions/` — flat; `StockItem`, `StockMovement`, `StockReservation` coexist directly
- `src/Pricing/Actions/` — flat; `Price`, `PriceList`, `Voucher` coexist directly

Documented? No — CLAUDE.md describes a flat layout only.
Plausible alternative: keep flat everywhere (Commerce flattens into `src/Commerce/Actions/CartAddTo.php` style), or document a "split when sub-domains diverge enough" rule and apply consistently (Inventory could split into `Stock/`, `Reservations/`, `Movements/`).
Suggested action: **document as-is** — decide whether sub-namespacing is allowed only for "umbrella" domains (Commerce) or generally available. Inventory is already at the threshold.

### [mixed] Event dispatch — `Dispatchable::dispatch()` vs. `event(new ...)` *(recurs from 2026-04-22)*
Occurrences: ~25× `EventClass::dispatch(...)` across all domains; 3× `event(new EventClass(...))` — all in Agent.

Examples:
- `src/Inventory/Actions/AdjustStock.php` — `StockAdjusted::dispatch(...)`
- `src/Commerce/Order/Actions/CreateOrder.php` — `OrderCreated::dispatch(...)`
- Outliers: `src/Agent/AgentTool.php:45,57`, `src/Agent/Http/Controllers/DynamicClientRegistrationController.php:65` — `event(new ...)` (the Agent events `ToolInvoked`, `ToolInvocationFailed`, `DynamicClientRegistered` lack the `Dispatchable` trait)

Documented? No.
Suggested action: **reconcile to one style** — three callsites + adding `Dispatchable` to three Agent events. Drift inside one newer domain.

### [implicit] Action verb families — already known, deferred
Per `CLAUDE.md:59`: *"Pick a verb family per domain and stay consistent (see TODO.md — verb-family audit pending)."* Variation is real (`Pricing` mixes `Create/Update/Delete/Resolve/Calculate/Apply`; `Media` uses `Store/Delete` not `Create/Delete`; `Inventory` mixes `Adjust/Reserve/Confirm/Release`). Listed for completeness — already on the user's TODO, not surfacing as a new finding.

## What I did NOT flag
- **Documented-but-not-applied gaps** (drift from settled rules, not accidental conventions): `Tax/` has no `README.md`, no `Concerns/` (despite a `HasTaxCategory` contract → no `InteractsWithTaxCategory` trait), and no `TaxSchema.php`. `Shipping/` and `Payment/` also miss `{Domain}Schema.php`. Per skill rules these are non-compliance, not undecided. Worth fixing, but not "audit findings."
- **Test coverage gaps** (Location, Logging, Media, Storefront, Taxonomy have zero feature tests) — coverage, not convention.
- **`config()` access style** — uniformly direct `config('domain.key')` calls; no typed config wrappers. Single style, plausibly intentional for a Laravel package, undocumented but unanimous → not a real choice point.
- **Single-action vs multi-action controllers** (flagged 2026-04-22) — only Commerce/Cart still has multi-action controllers (`CartController` 2 methods, `CartItemController` 3). Borderline: too small to call a recurring pattern, but also unchanged since last audit.
- **`final readonly class` for DTOs/events** (flagged 2026-04-22) — same drift as before in Agent's three event classes; reconciles trivially together with the `event()` finding above.
- **Naming/casing/strict_types** — uniform across the codebase; no drift to surface.
- **Filament Resources/RelationManagers placement** — variation looks framework-driven, not a convention question.
