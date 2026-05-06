# Convention Audit — 2026-04-22

## Audit target
`/home/jelte/projects/in-other-shops` — Laravel 12 multi-domain package (14 domains under `src/`, ~200 PHP classes, 25 test files). Each domain is a self-contained subtree, bundled together but designed for independent extraction.

## Documented conventions (reference, not findings)
- Registry pattern per domain (`CLAUDE.md:29`)
- `Has*` contracts / `InteractsWith*` trait companions (`CLAUDE.md:53-54`)
- `Filament/{Domain}Schema.php` factory classes (`CLAUDE.md:32`)
- Events: past tense, reads don't dispatch (`CLAUDE.md:33`)
- `LogSubscriber` per audit-relevant domain (`CLAUDE.md:34`)
- Factories ship inside the domain at `src/{Domain}/Database/Factories/` (`CLAUDE.md:36`)
- Actions are invokable, stateless, single-responsibility (`CLAUDE.md:65`)
- `final` classes (non-final models/resources), string-backed enums, `$guarded = []` + `casts()` method, `declare(strict_types=1)`, PSR-12 (`CLAUDE.md:61-68`)

## Accidental conventions

### [mixed] module boundaries — HTTP-related subfolders: flat vs. `Http/` prefix
Occurrences: 2 domains use `Http/` prefix (8 files under it); 1 domain puts them at domain root (~10 files)

Examples:
- `src/Storefront/Controllers/BrowsableListController.php`, `src/Storefront/Resources/CategoryResource.php`, `src/Storefront/Middleware/SetStorefrontContext.php` — flat at domain root
- `src/Commerce/Cart/Http/Controllers/CartController.php`, `src/Commerce/Cart/Http/Requests/AddToCartRequest.php`, `src/Commerce/Cart/Http/Resources/CartResource.php` — nested under `Http/`
- `src/Agent/Http/Middleware/AuthenticateAgentBearer.php` — nested under `Http/`

Documented? No. `docs/adding-a-new-domain.md` does not mention HTTP layout.
Plausible alternative: pick one — flat (`Controllers/`, `Resources/`, `Middleware/` at domain root) or Laravel-idiomatic (`Http/Controllers/`, `Http/Resources/`, `Http/Middleware/`).
Suggested action: **discuss with team** — the newer domains (Commerce/Cart, Agent) both chose `Http/`; Storefront may be the outlier that should migrate, or the decision may simply need to be written down before the next domain picks a third spelling.

### [mixed] controller/route structure — single-action invokable vs. multi-action resource
Occurrences: 4 single-action invokable controllers (Storefront); 2 multi-action resource controllers (Commerce/Cart)

Examples:
- `src/Storefront/Controllers/BrowsableListController.php::__invoke`, `src/Storefront/Controllers/CategoryShowController.php::__invoke`, `src/Storefront/Controllers/BrowsableShowController.php::__invoke` — one verb per class
- `src/Commerce/Cart/Http/Controllers/CartController.php` `show()` + `destroy()`; `src/Commerce/Cart/Http/Controllers/CartItemController.php` `store()` + `update()` + `destroy()`

Documented? No.
Plausible alternative: single-action (matches the "one invokable Action per verb" convention already used for the Actions layer) or resource-style.
Suggested action: **discuss with team** — the Actions convention argues for single-action controllers; aligning would make `Controller → Action` a 1:1 mapping everywhere.

### [implicit] data shapes — `final readonly class` for all DTOs and most Events
Occurrences: 12/12 DTOs; 31/33 event classes

Examples:
- `src/Pricing/DTOs/PriceBreakdown.php:11` — `final readonly class`
- `src/Inventory/Events/StockAdjusted.php:11` — `final readonly class` + `Dispatchable`
- Outliers: `src/Agent/Events/ToolInvoked.php:7` and `src/Agent/Events/ToolInvocationFailed.php` — `final class` with per-property `public readonly`, no `Dispatchable`

Documented? No. `CLAUDE.md:61-68` specifies `final` and string-backed enums but says nothing about `readonly` class-level or event structure.
Plausible alternative: stick with per-property `readonly` (PHP 8.1 style), or adopt `readonly class` universally.
Suggested action: **document as-is** — the dominant pattern is unambiguous; the Agent outliers look like drift from a newer domain that didn't see the house style.

### [mixed] event dispatch — `Dispatchable::dispatch()` vs. `event(new ...)`
Occurrences: 27 uses of `EventClass::dispatch(...)`; 2 uses of `event(new EventClass(...))`

Examples:
- `src/Inventory/Actions/AdjustStock.php:42` — `StockAdjusted::dispatch(...)`
- `src/Commerce/Order/Actions/CreateOrder.php:54` — `OrderCreated::dispatch(...)`
- Outliers: `src/Agent/AgentTool.php:45`, `src/Agent/AgentTool.php:57` — `event(new ToolInvoked(...))`

Documented? No.
Plausible alternative: both are valid Laravel idioms.
Suggested action: **reconcile to one style** — this is cheap to fix (two call sites + add `Dispatchable` to the two Agent events) and keeps the event surface uniform. Likely just drift inside a new domain.

### [implicit] error handling — all thrown exceptions are PHP built-ins
Occurrences: 20 `throw new` sites across 10 files; 0 custom domain exception classes (FlowChain's `FlowChainRollbackSignal` / `StepFailedException` are internal flow-control, not user-facing domain errors)

Examples:
- `src/Pricing/Actions/ApplyVoucher.php:56-73` — four `InvalidArgumentException` with string messages (`"Voucher not found."`, `"Voucher is no longer valid."`, etc.)
- `src/Inventory/Actions/ReserveStock.php:69` — `RuntimeException` for insufficient stock
- `src/Payment/Actions/RefundPayment.php:42,57,61` — three `InvalidArgumentException`

Documented? No.
Plausible alternative: custom typed exceptions per domain (`VoucherNotFoundException`, `InsufficientStockException`, `RefundAmountExceededException`) that consumers can catch discriminately.
Suggested action: **discuss with team** — two legitimate stances: (a) "we don't do custom exceptions, string messages are enough" (write it down); (b) introduce typed exceptions before the first consumer needs to catch a specific failure. Today, a consumer catching `InvalidArgumentException` from `ApplyVoucher` can't tell "bad currency" from "voucher expired" without string-matching.

## What I did NOT flag
- `.gitkeep` files left in directories that now contain PHP (13 stale placeholders) — cleanup, not a convention.
- Lowercase `config/` directory vs. PascalCase siblings — Laravel publishing convention.
- `#[Test]` attribute used on all 81 test methods — consistent and uncontroversial, no real decision pending.
- Commerce's sub-domain nesting (`Cart/`, `Order/`, `Customer/`) — only one domain does this, so it's not yet a repeated pattern; worth revisiting if a second domain splits.
- Non-final Filament Resources / RelationManagers — framework-imposed (Filament expects extension).
