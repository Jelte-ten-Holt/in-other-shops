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
Commerce ─────── depends on Location, Currency, Payment, Shipping; soft-deps on Inventory (cart stock guard, opt-in via HasStock)
Storefront ───── depends on Currency, Pricing, Taxonomy, Translation, Media, Inventory
```

Adding a dependency between domains is a significant decision — it means those domains must be extracted together or one must depend on the other. Flag new cross-domain dependencies to the developer.

### Key Patterns

- **Registry pattern:** Each domain with models has a registry class at its root (e.g., `Taxonomy.php`, `Commerce.php`). It resolves model classes via config so consuming projects can extend them.
- **Contract + Concern:** Each domain ships `Contracts/Has{X}` and `Concerns/InteractsWith{X}`. Project models implement the contract and use the trait. Contracts always use the `Has*` prefix for capability contracts (no `*able` suffix — see Naming).
- **Filament Schema classes:** Domains ship `Filament/{Domain}Schema.php` with static factory methods returning preconfigured Filament form components.
- **Domain events:** State changes dispatch events (past tense: `StockAdjusted`, `MediaStored`). Reads and calculations do not.
- **Domain log subscribers:** Domains with audit-relevant events ship a `Listeners/{Domain}LogSubscriber.php` auto-discovered by Laravel 11+. Subscribers route domain events through `LogDispatcher` to per-domain Monolog channels. Consumers override channels/handlers via config; they do not re-implement subscribers. Logging is package functionality, not a consumer concern. Note: Media and Taxonomy dispatch events but intentionally have no LogSubscriber yet — admin-activity logging is deferred until multi-user.
- **Config-driven models:** Every domain config includes a `models` key. The registry resolves classes through this config.
- **Factories ship with the domain:** every model with a factory ships that factory in `src/{Domain}/Database/Factories/`. `newFactory()` on the model points into the package namespace. Tests — both the package's own PHPUnit suite and consumer tests — rely on the package's factories.
- **FlowChain error semantics:** steps signal failure by throwing. `FlowChain` wraps the run in a DB transaction; any exception triggers `FlowChainRollbackSignal`, rolls back the transaction, and is converted into a failed `FlowChainResult`. Steps do not return errors — they throw, and FlowChain handles the rest. See `src/FlowChain/README.md` for the full contract.

### What Does NOT Belong in This Package

- Project-specific models (Product, Bundle, etc.) — these are defined by the consuming project
- Project-specific orchestration (checkout flows, listeners that wire domains together)
- Seeders — project-specific data belongs in the consuming project
- Authentication — every project handles this differently

### Naming

- **Capability contracts use `Has*`.** `HasCart`, `HasOrders`, `HasMedia`, `HasPrices`, `HasStorefrontPresence`, `HasTranslations`. No `*able` suffix — `Has*` is shorter and unambiguously marks a contract as a package capability attach-point.
- **Trait companions use `InteractsWith*`.** One trait per contract; the trait implements the contract's relation methods plus thin default behaviour.
- **Actions:** verb-noun, single responsibility, invokable. Pick a verb family per domain and stay consistent (see `TODO.md` — verb-family audit pending).

## Coding Standards

- **PSR-12** with `declare(strict_types=1)` on all PHP files
- **`final` classes** unless inheritance is explicitly needed (models are non-final for extension via registry)
- **Actions** are invokable, stateless, single-responsibility
- **Enums** are always string-backed
- **Models** use `protected $guarded = []`, method-syntax `casts()`, morph map aliases
- **Service providers** are `final`, register config in `register()`, load migrations and morph maps in `boot()`

## Commands

The package runs its own PHPUnit suite (Orchestra Testbench) — `composer test`. Consuming projects can run their own tests on top; they should not be the only safety net.

The package does not ship application-level CLI commands. Exceptions: inventory housekeeping (`inventory:release-expired`, gated behind config) and cart cleanup (`commerce:prune-carts`, prunes expired guest carts).

## Adding a New Domain

Checklist moved to [docs/adding-a-new-domain.md](docs/adding-a-new-domain.md).
