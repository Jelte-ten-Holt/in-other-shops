# In Other Shops

Modular e-commerce domain packages for Laravel 12+, bundled as a single Composer package.

## Architecture

### A Modular Monolith With One Real Seam

`in-other-shops` is **one** Composer package, deliberately organized as internal domains under `src/`. The organizing goal is a **modular monolith** — clean domain boundaries that keep a 29k-line package navigable and maintainable — **not** a staging ground for 18 independently extractable packages.

The earlier "every domain is independently extractable" framing has been retired (2026-07-02). It stopped being true — two-thirds of the package can't be cleanly separated (see tiers) — it aimed the extraction discipline at the tier least able to use it (the shop core, which no one will ever reuse), and its one concrete pain, migrations running in every consumer, has a cheaper fix than a split: a per-domain `{domain}.migrations.enabled` config gate.

**The modular discipline stays regardless — it earns its keep as maintainability, independent of any split:**
- `Has*` contracts + `InteractsWith*` traits decouple the package from each consumer's models. Load-bearing for having 2+ consumers.
- Config namespacing, explicit event wiring, and the `Support\DomainServiceProvider` base keep the canonical per-domain sequence from drifting.
- Per-domain factories, symmetric structure, and the architecture test keep the package self-enforcing.

What we drop is the *pretense that any domain can become its own package*. Keep the boundaries clean; stop contorting the code to preserve separability that will never be cashed in.

### The Four Tiers (and the one seam that matters)

The real structure is not 18 peers:

- **Generic leaves** — Currency, Translation, Logging, Location, Media, FlowChain, Support. Domain-neutral, genuinely decoupled, and the **only** tier where extraction is both feasible *and* valuable (a future non-shop app could reuse Logging or Media). **This is the one seam worth protecting** — keep these sharply independent.
- **Middle band** — Pricing, Taxonomy, Payment, Tax, Inventory. Depend only on leaves. Extractable in principle, but there is no reuse case outside a shop, so don't spend effort keeping them split-ready beyond ordinary hygiene.
- **Shop core** — Commerce + Shipping + Purchasing. Treat as **one unit.** Commerce's transitive closure is most of the package, and Shipping↔Commerce is a live cycle. Cross-coupling *within* this cluster is expected and fine — do not contort it for separability. If the package is ever split, this cluster moves together as a single "shop core" package.
- **Integration tier** — Storefront, Variants, Agent. Sit on top, depend on many domains, and are **not extractable by design** — they wire the package to a specific consumer surface (read API, variant catalog, MCP).

**Extraction policy:** on-demand and leaf-only. Split a generic leaf into its own lower-level package **only** when a concrete non-shop consumer needs it without the shop. Never split speculatively; never try to separate the shop core or the integration tier.

### Domain Dependency Graph

Hard dep = concrete model, action, DTO, or exception import. Soft dep = contract, event, or registry call only. Config-key reads (e.g. one domain reading another's config) are a coupling category too — grep for `use` statements misses them. Logging is consumed by nearly every domain via subscribers — noted inline rather than repeated.

```
Generic leaves — keep sharply independent (the one real seam):
  Currency ────── (independent, foundational)
  Translation ─── (independent, foundational)
  Logging ─────── (independent — but CONSUMED by nearly every domain via subscribers; would need to publish first in any split)
  Location ────── (independent)
  Media ───────── (independent)
  FlowChain ───── (independent of domain code)
  Support ─────── shared kernel: DomainServiceProvider base + Filament base classes + StateTransitions; consumed by ~12 domains; itself depends on Currency (MoneyFields) + Filament

Middle band — depend only on leaves:
  Pricing ─────── depends on Currency
  Taxonomy ────── depends on Translation, Media (Category implements HasMedia for a cover image)
  Payment ─────── depends on Currency
  Tax ─────────── depends on Location
  Inventory ───── soft-dep on Translation (HasLocaleGroup instanceof in AdjustStock) ⚠ drift — to remove via a SharesInventory contract

Shop core — one unit; moves together if ever split; internal coupling is expected, not drift:
  Commerce ────── depends on Currency, Location, Payment, Pricing, Shipping, Tax, FlowChain (registers AddToCartChain); Inventory contracts + InsufficientStockException
  Shipping ────── depends on Currency, Location; hard-dep on Commerce (OrderLine in ShipmentItem) — cycle with Commerce, ACCEPTED within shop core. The Shippable-polymorphism fix is only needed if a non-order shipment use case appears (warehouse transfer, B2B sample, returns)
  Purchasing ──── depends on Inventory (AdjustStock action, StockMovementReason enum), Tax (TaxCategory enum)
  Tracking ────── depends on Commerce (CartItem/OrderLine via the Commerce registry; AddToCartPayload), FlowChain (AbstractFlowStep). Both attribution tables FK into Commerce tables, so it moves with the shop core

Integration tier — not extractable by design:
  Storefront ──── depends on Currency, Inventory, Media, Pricing, Taxonomy, Translation
  Variants ────── depends on Commerce (package Variant implements HasCart), Inventory, Media, Pricing, Translation; also READS commerce.cart.api.default_currency config. No cycle — Commerce does not depend on Variants. Adopted only by consumers with variant catalogs (bianka), not in-other-worlds (flat SKUs)
  Agent ────────── depends on Commerce, Inventory, Storefront, Taxonomy — top of the graph
```

Drift cases and fix shapes are tracked in [docs/audits/2026-05-13/dep-graph-audit.md](docs/audits/2026-05-13/dep-graph-audit.md) (read its top banner — its "blocking for a split" framing predates the modular-monolith reframe).

Adding a dependency is judged by tier, not by a blanket "significant decision" rule: **coupling within the shop core is fine**; a new dep that reaches **into a generic leaf**, or makes a **leaf depend on a non-leaf**, is the thing to flag — it erodes the one seam worth protecting. Flag those to the developer.

### Key Patterns

- **Registry pattern:** Each domain with models has a registry class at its root (e.g., `Taxonomy.php`, `Commerce.php`). It resolves model classes via config so consuming projects can extend them.
- **Contract + Concern:** Each domain ships `Contracts/Has{X}` plus a paired `Concerns/InteractsWith{X}` trait **when the trait carries default relations, scopes, or behavior** the consuming model can inherit. Pure-interface contracts that only declare a method the model must implement itself (e.g. `HasTaxCategory::taxCategory()`) ship without a trait — an empty trait is cargo-culting. Contracts always use the `Has*` prefix (no `*able` suffix — see Naming).
- **Filament Schema classes:** Domains that expose form fragments meant to attach to consuming-project models (`InventorySchema::stockFields`, `MediaSchema`, `PricingSchema`, etc.) ship `Filament/{Domain}Schema.php` with static factory methods. Domains whose Filament surface is only standalone admin Resources (Tax, Shipping, Payment) do not need a Schema class. A consumer Edit page that mounts a manual-sync Schema ends its `afterSave()` with `SyncsManualFormState::refillManualFormState()` — the page stays on itself after a save, and without the refill the next save re-applies one-shot fields and churns media rows. `tests/Stubs/Filament/StubEditableResource` is the reference page; drive Schema behaviour through it (see `docs/writing-tests.md`), not only through the static `fillFormData`/`saveFormData` halves.
- **Domain events:** State changes dispatch events (past tense: `StockAdjusted`, `MediaStored`). Reads and calculations do not. Event classes are `final readonly class` and use the `Dispatchable` trait; dispatch via `EventClass::dispatch(...)`, never the `event(new EventClass(...))` helper.
- **Domain log subscribers:** Domains with audit-relevant events ship a `Listeners/{Domain}LogSubscriber.php` (a class with a `subscribe(Dispatcher $events): array` method) that each domain's service provider **registers explicitly** via `Event::subscribe({Domain}LogSubscriber::class)` in `boot()` — not by Laravel's `handle*` auto-discovery. Registering explicitly is deliberate: it survives `event:cache`, and it means a consumer that disables listener auto-discovery (`->withEvents(discover: false)`) does not silently lose the package's audit trail. **When you add a new domain log subscriber, add the `Event::subscribe(...)` call to that domain's provider — a subscriber that isn't subscribed never fires.** Subscribers route domain events through `LogDispatcher` to per-domain Monolog channels. Consumers override channels/handlers via config; they do not re-implement subscribers. Logging is package functionality, not a consumer concern. Note: Media and Taxonomy dispatch events but intentionally have no LogSubscriber yet — admin-activity logging is deferred until multi-user.
- **Config-driven models:** Every domain config includes a `models` key. The registry resolves classes through this config.
- **Audit-log actor attribution:** every `domain_logs` row records *who* via a `LogActor`, resolved by `LogDispatcher` with the precedence **explicit (`LogEntry.actor`) > ambient (`LogContext`) > `LogActor::unknown()`**. The actor is set **once per request/job boundary** — never threaded through events or action signatures. When you add a new boundary that emits audit events, establish the actor there: a state-mutating scheduled command uses the `Logging\Concerns\RunsAsSystemActor` concern (`$this->beginSystemAuditActor()` at the top of `handle()`); a gateway/webhook handler sets `LogActor::gateway(...)`; the agent middleware sets `LogActor::agent(...)`; consumers set user/guest in their own request middleware. Only an operation that knows its actor better than the boundary does (refunds, derived from `RefundActor`) passes an explicit `LogEntry.actor`. A row attributed to `unknown` is a tripwire — a boundary forgot to set one. **A new audit channel also needs an `AuditPipelineRowTest` case** asserting a real row through the dispatch→subscribe→handler path. Guard rail: every audit `*LogSubscriber` is synchronous today; if one is ever made `ShouldQueue`, it must capture the actor at dispatch and re-establish it in the job (a queued listener would otherwise inherit a worker's empty `LogContext`).
- **Factories ship with the domain:** every model with a factory ships that factory in `src/{Domain}/Database/Factories/`. Models point at it with `protected static string $factory = XFactory::class;` (not a `newFactory()` method — converted package-wide in T-M1; the Commerce subdomains share `Commerce\Database\Factories`, so convention-based resolution would misfire). Tests — both the package's own PHPUnit suite and consumer tests — rely on the package's factories. Behaviour shared by two or more factories lives in a `{Domain}/Database/Factories/Concerns/` trait (e.g. `Commerce\...\Concerns\CreatesAddressPair`, the shipping+billing `withAddresses()` pair used by `OrderFactory` and `CustomerFactory`).
- **FlowChain error semantics:** steps signal failure by throwing. `FlowChain` wraps the run in a DB transaction; any exception triggers `FlowChainRollbackSignal`, rolls back the transaction, and is converted into a failed `FlowChainResult`. Steps do not return errors — they throw, and FlowChain handles the rest. See `src/FlowChain/README.md` for the full contract.
- **FlowChains are publish-and-modify artifacts:** the package's primary purpose for FlowChain is publishable chains. The package ships chain definitions as `PublishableFlowChain` subclasses (non-final); consumers run `php artisan flowchain:publish <ChainFQN>` to land a copy at `app/Project/FlowChains/{Domain}/{ChainName}.php`. The published copy `extends Package{ChainName}` and overrides `steps()` to insert/remove/reorder. `FlowChainRegistry` discovers the published copy at resolution time via file existence; falls back to the package default when no published copy exists. The first published chain is `Commerce\Cart\FlowChains\AddToCartChain`. See `src/FlowChain/README.md` §Publishing for the full workflow. Steps are public API the moment they ship — consumers depend on each step's payload contract.
- **FlowChain step discipline:** because every step's payload read/write surface becomes public API on publish, steps are designed for stable, additive evolution. **When introducing a new step or designing a new chain, think about what consumers might need to do with it later, not just what the current consumer needs.** Conventions: (1) **one step = one verb** — if a step needs "and" to describe, split it (`SelectShippingMethod` doing zone+method+cost is the cautionary example); (2) **stable payload contracts** — once a step writes `payload->taxRate`, the field's name/type/meaning is fixed and grows only by addition; renames and retypes are breaking changes; (3) **branching belongs in the chain via `->when()`, not buried inside steps** — hidden conditional sub-behaviors block consumers from replacing just the conditional logic; (4) **explicit `@reads` / `@writes` docblocks** on each step in place of runtime ordering assertions like `assert($payload->taxRate !== null)` — the contract is "writes `taxRate` to the payload," not "must run after `ResolveTaxRateForOrder`"; (5) **naming**: verb-noun step names with precise scope (`ResolveTaxRateForOrder` ✓, `HandleTax` ✗); domain-first payload field names (`taxRate`, `shippingMethod`); no abbreviation drift (one casing, one spelling, everywhere).
- **HTTP layout:** Domains that expose HTTP endpoints place every HTTP-layer class under `src/{Domain}/Http/` — `Http/Controllers/`, `Http/Resources/`, `Http/Requests/`, `Http/Middleware/`, `Http/Routes/`, `Http/Support/`. Never at the domain root.
- **Sub-namespacing:** Most domains stay flat (`Actions/`, `Models/`, `Concerns/` directly under the domain). A domain may sub-namespace into peer aggregates (e.g. Commerce: `Cart/`, `Order/`, `Customer/`, each with its own `Actions/`/`Models/`/etc.) when the aggregates have genuinely disjoint surfaces and shared parent-domain code is minimal. Promote a flat domain only when the split clarifies; if it starts feeling forced, that's the signal the domain should be extracted into separate domains instead.
- **Exception strategy:** Domain-rule rejections throw a per-domain exception (`InOtherShops\\{Domain}\\Exceptions\\{Domain}Exception` base, or a specific subclass like `VoucherInvalidException`, `InsufficientStockException`, `RefundAmountExceededException` for outcomes a caller might branch on). The base extends `\\DomainException` (LogicException family). Reserve `\\InvalidArgumentException` for actually-malformed inputs (unknown enum values, schema-violations, type mismatches). String messages alone are not enough — the type carries the meaning.
- **Action input DTOs:** Actions take positional parameters by default. **Callers of any action with 4+ parameters use named arguments** so callsites stay readable when params have similar types. Promote to an input DTO (`{Domain}/DTOs/{Action}Request.php`, `final readonly class`) only when the same input shape is constructed at 3+ distinct callsites — at that point the DTO becomes a passable value, not just named-args-in-a-class — or when an action is high-churn and expected to take repeated additive changes (e.g. Price create/update), where a shared `{Noun}Data` DTO absorbs new fields without resignaturing callers. Tighten parameter types as much as the language allows (intersection types like `Model&HasStock`, typed enums, value objects) before reaching for a DTO. Output DTOs remain case-by-case; multi-field result objects (`PriceBreakdown`, `InitiatePaymentResult`) wrap, single-value returns don't.

### What Does NOT Belong in This Package

- Project-specific models (Product, Bundle, etc.) — these are defined by the consuming project
- Project-specific orchestration (the checkout **chain** and its steps, listeners that wire domains together). Amended 2026-08-22: the package MAY ship **optional checkout-adjacent modules** — quote-side actions (`Commerce\Checkout\QuoteCheckout`) and consumer-mounted HTTP surfaces (the voucher apply/remove pair) — once both consumers have independently built the same thing. The chain itself stays consumer-owned, and checkout HTTP routes are **never auto-registered** (consumers mount `CheckoutRoutes::*` inside their own localized groups).
- Frontend assets — the package is storefront-language-agnostic: it emits data shapes (DTOs in cents, presenter arrays), never Vue/JS/Blade storefront components
- Seeders — project-specific data belongs in the consuming project
- Authentication — every project handles this differently

### Presenters (new convention, v0.60.0)

`Commerce\Order\Support\OrderSummary` is the package's first presenter: a `final` static class shaping a model into the array a storefront renders. The dividing line: **DTOs carry cents; presenters carry formatted strings** (plus the cents alongside). Presenters emit package data only — anything presentation-specific (status label/color pairs, per-line URLs, i18n'd labels) is the consumer's to decorate. New presenters live in `{Domain}/.../Support/`.

### Naming

- **Capability contracts use `Has*`.** `HasCart`, `HasOrders`, `HasMedia`, `HasPrices`, `HasStorefrontPresence`, `HasTranslations`, `HasLocaleGroup`. No `*able` suffix — `Has*` is shorter and unambiguously marks a contract as a package capability attach-point.
- **Trait companions use `InteractsWith*`.** One trait per contract; the trait implements the contract's relation methods plus thin default behaviour.
- **Actions:** verb-noun, single responsibility, invokable. Per-domain verb families and the cross-domain glossary of reserved verbs (`Calculate`, `Resolve`, `Apply`, `Process`, `Initiate`, etc.) are documented in [docs/verb-families.md](docs/verb-families.md). Compliance is a real review item.

## Coding Standards

- **PSR-12** with `declare(strict_types=1)` on all PHP files
- **`final` classes** unless inheritance is explicitly needed (models are non-final for extension via registry)
- **Actions** are invokable, stateless, single-responsibility
- **Enums** are always string-backed. Admin `label()` comes from the `Support\HasLabel` trait — a sentence-case transform of the backing value (`partially_received` → "Partially received"). `use HasLabel` rather than hand-rolling a `match`; override `label()` only for a label the value can't produce (an ampersand, an abbreviation), delegating the ordinary cases to `defaultLabel()`. It satisfies `Transitionable::label()` for status enums.
- **Models** use `protected $guarded = []`, method-syntax `casts()`, morph map aliases
- **Service providers** are `final`, register config in `register()`, load migrations and morph maps in `boot()`
- **Status columns** use the `$table->status()` Blueprint macro (registered once in `Support\SupportServiceProvider`) — `string(30)` + a single-column index. Pass `status(index: false)` when the column carries its own composite index. This is the convention for **new** status columns; the pre-existing five (orders/payments/shipments/stock_reservations/purchase_orders) are deliberately left on their original heterogeneous shape — retrofitting them meant editing shipped create migrations in place, which never re-runs for a consumer that already migrated (T-B-MIG1-REVISE). Changes to existing status columns ship as additive forward migrations instead.

## Tests

The package runs its own PHPUnit suite (Orchestra Testbench) — `composer test`. Consuming projects can run their own tests on top; they should not be the only safety net.

How to write tests for this package — trust principles, what each Action/Listener/HTTP/Command suite must cover, and concrete good-and-bad examples — is in [docs/writing-tests.md](docs/writing-tests.md). Read it before adding tests; the rules there are how the package decides whether a test earns its keep.

## Commands

The package does not ship application-level CLI commands. Exceptions: inventory housekeeping (`inventory:release-expired`, gated behind config), cart cleanup (`commerce:prune-carts`, prunes expired guest carts), and webhook ledger pruning (`payment:prune-webhook-events`, retention via `payment.webhook_retention_days`, default 90). The full catalog of commands, their schedule state, and every other periphery actor this package contributes to consumers lives in [docs/periphery.md](docs/periphery.md).

## Periphery

`docs/periphery.md` is the authoritative list of everything this package contributes to a consumer's runtime **plus** the external API surface consumers depend on. It has two halves:

- **Runtime sections** — auto-scheduled commands, registered-but-not-scheduled commands, event subscribers, listeners, model observers, model boot hooks (`saving`/`deleting`), and dispatched events. What fires in a consumer's app when this package is installed.
- **External surface section** — `Has*` contracts, `InteractsWith*` traits, public action signatures, dispatched events with consumer subscribers, Filament Schema static factories, FlowChain published chains and step payload contracts, and the per-consumer shape-parity wrapper files (`ClaimGuestCart`, `HandlePaymentSucceeded`, `HandlePaymentFailed`, `ReserveItems`, etc.). What consumers depend on at the type level.

Consumers reference this doc rather than re-deriving the catalog from `vendor/`. The version that applies is whatever is pinned in their `composer.lock`.

**When adding, removing, or modifying any periphery actor or external-surface symbol** — a new `Schedule::command(...)` in a ServiceProvider, a new `Event::subscribe(...)`, a new model boot hook, a renamed dispatched event, a method added to a `Has*` contract, a public action signature change, a FlowChain step payload field renamed — **update `docs/periphery.md` and refresh its "Last verified" date in the same change.** Same discipline as updating tests. Cross-consumer audit: External surface changes affect every consumer; sweep both consumer `docs/periphery.md` files for impact before releasing.

## Releasing

**Both consumers should sit on the same version, and drift is closed deliberately rather than left to the next person who happens to bump.** `in-other-worlds` and `bianka-shop-one` consume one package, and the whole point of `Has*`/`InteractsWith*` is that a fix lands once and reaches both. When they diverge, that stops being true in a way nothing checks: a behaviour reasoned about in one app is a version behind in the other, a fix looks shipped while only half the estate has it, and the periphery docs describe versions that are no longer both installed. It also silently widens the surface a future release has to be correct against.

So: after cutting a release, bump **both** consumers, even when only one asked for the change. Where a consumer genuinely cannot follow — real conflict, work in flight — say so in that consumer's `docs/periphery.md` status line with the version it is actually on and why, so the gap is a recorded decision instead of an accident. The status line is the thing that goes stale first (bianka's said v0.62.0 while its composer.json said `^0.64` — two bumps behind), so correct it in the same change as the bump.

**A pushed tag is immutable.** Packagist resolves a tag to a commit **once** and does not re-resolve a moved one, so re-pointing a tag after pushing it leaves Packagist serving the old commit under the new version number — and nothing catches it: this package's suite tests `src/` regardless of what got tagged, and a consumer's suite passes because it has no test for a fix it never received. That is exactly how `v0.67.0` shipped the Filament page fixture without any of the fixes it exists to prove (found only when a consumer's `composer update` produced a trait with the new method missing). If a tag is wrong after it is pushed, **cut the next patch version** — never move it — and floor the consumers' constraints above the bad tag (`^0.67.1`, not `^0.67`) so no fresh install can resolve back to it.

## Adding a New Domain

Checklist moved to [docs/adding-a-new-domain.md](docs/adding-a-new-domain.md).
