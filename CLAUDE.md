# In Other Shops

Modular e-commerce domain packages for Laravel 12+, bundled as a single Composer package.

## Architecture

### Domains Are Independently Extractable

Every directory under `src/` is a self-contained domain package. They are bundled here for convenience — the plan is to extract individual domains into their own Composer packages as the need arises (e.g., when a new project only needs a subset).

**This means:**
- Domains must not be coupled to each other beyond their declared dependencies (see table below).
- Each domain has its own service provider, migrations, config, contracts, concerns, and optionally Filament components.
- Cross-domain relationships use polymorphic morphs, never direct foreign keys.
- When a domain depends on another, it uses the other domain's registry class and contracts — never concrete model imports.

### Domain Dependency Graph

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
Commerce ─────── depends on Location, Currency, Payment, Shipping, Tax; soft-deps on Inventory (cart stock guard, opt-in via HasStock)
Storefront ───── depends on Currency, Pricing, Taxonomy, Translation, Media, Inventory
```

Adding a dependency between domains is a significant decision — it means those domains must be extracted together or one must depend on the other. Flag new cross-domain dependencies to the developer.

### Key Patterns

- **Registry pattern:** Each domain with models has a registry class at its root (e.g., `Taxonomy.php`, `Commerce.php`). It resolves model classes via config so consuming projects can extend them.
- **Contract + Concern:** Each domain ships `Contracts/Has{X}` plus a paired `Concerns/InteractsWith{X}` trait **when the trait carries default relations, scopes, or behavior** the consuming model can inherit. Pure-interface contracts that only declare a method the model must implement itself (e.g. `HasTaxCategory::taxCategory()`) ship without a trait — an empty trait is cargo-culting. Contracts always use the `Has*` prefix (no `*able` suffix — see Naming).
- **Filament Schema classes:** Domains that expose form fragments meant to attach to consuming-project models (`InventorySchema::stockFields`, `MediaSchema`, `PricingSchema`, etc.) ship `Filament/{Domain}Schema.php` with static factory methods. Domains whose Filament surface is only standalone admin Resources (Tax, Shipping, Payment) do not need a Schema class.
- **Domain events:** State changes dispatch events (past tense: `StockAdjusted`, `MediaStored`). Reads and calculations do not. Event classes are `final readonly class` and use the `Dispatchable` trait; dispatch via `EventClass::dispatch(...)`, never the `event(new EventClass(...))` helper.
- **Domain log subscribers:** Domains with audit-relevant events ship a `Listeners/{Domain}LogSubscriber.php` auto-discovered by Laravel 11+. Subscribers route domain events through `LogDispatcher` to per-domain Monolog channels. Consumers override channels/handlers via config; they do not re-implement subscribers. Logging is package functionality, not a consumer concern. Note: Media and Taxonomy dispatch events but intentionally have no LogSubscriber yet — admin-activity logging is deferred until multi-user.
- **Config-driven models:** Every domain config includes a `models` key. The registry resolves classes through this config.
- **Factories ship with the domain:** every model with a factory ships that factory in `src/{Domain}/Database/Factories/`. `newFactory()` on the model points into the package namespace. Tests — both the package's own PHPUnit suite and consumer tests — rely on the package's factories.
- **FlowChain error semantics:** steps signal failure by throwing. `FlowChain` wraps the run in a DB transaction; any exception triggers `FlowChainRollbackSignal`, rolls back the transaction, and is converted into a failed `FlowChainResult`. Steps do not return errors — they throw, and FlowChain handles the rest. See `src/FlowChain/README.md` for the full contract.
- **FlowChains are publish-and-modify artifacts:** the package's primary purpose for FlowChain is publishable chains. The package ships chain definitions; consumers publish a copy into their own app (`vendor:publish`-style discovery, deferred — see TODO) to alter individual steps while keeping the rest. This means a published chain's steps are public API the moment they ship — consumers fork the chain file and depend on each step's payload contract. Orchestration is the means; publishability is the goal.
- **FlowChain step discipline:** because every step's payload read/write surface becomes public API on publish, steps are designed for stable, additive evolution. **When introducing a new step or designing a new chain, think about what consumers might need to do with it later, not just what the current consumer needs.** Conventions: (1) **one step = one verb** — if a step needs "and" to describe, split it (`SelectShippingMethod` doing zone+method+cost is the cautionary example); (2) **stable payload contracts** — once a step writes `payload->taxRate`, the field's name/type/meaning is fixed and grows only by addition; renames and retypes are breaking changes; (3) **branching belongs in the chain via `->when()`, not buried inside steps** — hidden conditional sub-behaviors block consumers from replacing just the conditional logic; (4) **explicit `@reads` / `@writes` docblocks** on each step in place of runtime ordering assertions like `assert($payload->taxRate !== null)` — the contract is "writes `taxRate` to the payload," not "must run after `ResolveTaxRateForOrder`"; (5) **naming**: verb-noun step names with precise scope (`ResolveTaxRateForOrder` ✓, `HandleTax` ✗); domain-first payload field names (`taxRate`, `shippingMethod`); no abbreviation drift (one casing, one spelling, everywhere).
- **HTTP layout:** Domains that expose HTTP endpoints place every HTTP-layer class under `src/{Domain}/Http/` — `Http/Controllers/`, `Http/Resources/`, `Http/Requests/`, `Http/Middleware/`, `Http/Routes/`, `Http/Support/`. Never at the domain root.
- **Sub-namespacing:** Most domains stay flat (`Actions/`, `Models/`, `Concerns/` directly under the domain). A domain may sub-namespace into peer aggregates (e.g. Commerce: `Cart/`, `Order/`, `Customer/`, each with its own `Actions/`/`Models/`/etc.) when the aggregates have genuinely disjoint surfaces and shared parent-domain code is minimal. Promote a flat domain only when the split clarifies; if it starts feeling forced, that's the signal the domain should be extracted into separate domains instead.
- **Exception strategy:** Domain-rule rejections throw a per-domain exception (`InOtherShops\\{Domain}\\Exceptions\\{Domain}Exception` base, or a specific subclass like `VoucherInvalidException`, `InsufficientStockException`, `RefundAmountExceededException` for outcomes a caller might branch on). The base extends `\\DomainException` (LogicException family). Reserve `\\InvalidArgumentException` for actually-malformed inputs (unknown enum values, schema-violations, type mismatches). String messages alone are not enough — the type carries the meaning.
- **Action input DTOs:** Actions take positional parameters by default. **Callers of any action with 4+ parameters use named arguments** so callsites stay readable when params have similar types. Promote to an input DTO (`{Domain}/DTOs/{Action}Request.php`, `final readonly class`) only when the same input shape is constructed at 3+ distinct callsites — at that point the DTO becomes a passable value, not just named-args-in-a-class. Tighten parameter types as much as the language allows (intersection types like `Model&HasStock`, typed enums, value objects) before reaching for a DTO. Output DTOs remain case-by-case; multi-field result objects (`PriceBreakdown`, `InitiatePaymentResult`) wrap, single-value returns don't.

### What Does NOT Belong in This Package

- Project-specific models (Product, Bundle, etc.) — these are defined by the consuming project
- Project-specific orchestration (checkout flows, listeners that wire domains together)
- Seeders — project-specific data belongs in the consuming project
- Authentication — every project handles this differently

### Naming

- **Capability contracts use `Has*`.** `HasCart`, `HasOrders`, `HasMedia`, `HasPrices`, `HasStorefrontPresence`, `HasTranslations`, `HasLocaleGroup`. No `*able` suffix — `Has*` is shorter and unambiguously marks a contract as a package capability attach-point.
- **Trait companions use `InteractsWith*`.** One trait per contract; the trait implements the contract's relation methods plus thin default behaviour.
- **Actions:** verb-noun, single responsibility, invokable. Per-domain verb families and the cross-domain glossary of reserved verbs (`Calculate`, `Resolve`, `Apply`, `Process`, `Initiate`, etc.) are documented in [docs/verb-families.md](docs/verb-families.md). Compliance is a real review item.

## Coding Standards

- **PSR-12** with `declare(strict_types=1)` on all PHP files
- **`final` classes** unless inheritance is explicitly needed (models are non-final for extension via registry)
- **Actions** are invokable, stateless, single-responsibility
- **Enums** are always string-backed
- **Models** use `protected $guarded = []`, method-syntax `casts()`, morph map aliases
- **Service providers** are `final`, register config in `register()`, load migrations and morph maps in `boot()`

## Tests

The package runs its own PHPUnit suite (Orchestra Testbench) — `composer test`. Consuming projects can run their own tests on top; they should not be the only safety net.

How to write tests for this package — trust principles, what each Action/Listener/HTTP/Command suite must cover, and concrete good-and-bad examples — is in [docs/writing-tests.md](docs/writing-tests.md). Read it before adding tests; the rules there are how the package decides whether a test earns its keep.

## Commands

The package does not ship application-level CLI commands. Exceptions: inventory housekeeping (`inventory:release-expired`, gated behind config) and cart cleanup (`commerce:prune-carts`, prunes expired guest carts).

## Adding a New Domain

Checklist moved to [docs/adding-a-new-domain.md](docs/adding-a-new-domain.md).
