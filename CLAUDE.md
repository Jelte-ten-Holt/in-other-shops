# In Other Shops

Modular e-commerce domain packages for Laravel 12, bundled as a single Composer package.

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
Commerce ─────── depends on Location, Currency, Payment, Shipping
Storefront ───── depends on Currency, Pricing, Taxonomy, Translation, Media, Inventory
```

Adding a dependency between domains is a significant decision — it means those domains must be extracted together or one must depend on the other. Flag new cross-domain dependencies to the developer.

### Key Patterns

- **Registry pattern:** Each domain with models has a registry class at its root (e.g., `Taxonomy.php`, `Commerce.php`). It resolves model classes via config so consuming projects can extend them.
- **Contract + Concern:** Each domain ships `Contracts/Has{X}` and `Concerns/InteractsWith{X}`. Project models implement the contract and use the trait.
- **Filament Schema classes:** Domains ship `Filament/{Domain}Schema.php` with static factory methods returning preconfigured Filament form components.
- **Domain events:** State changes dispatch events (past tense: `StockAdjusted`, `MediaStored`). Reads and calculations do not.
- **Config-driven models:** Every domain config includes a `models` key. The registry resolves classes through this config.

### What Does NOT Belong in This Package

- Project-specific models (Product, Bundle, etc.) — these are defined by the consuming project
- Project-specific orchestration (checkout flows, listeners that wire domains together)
- Factories and seeders — these are project concerns
- Authentication — every project handles this differently

## Coding Standards

- **PSR-12** with `declare(strict_types=1)` on all PHP files
- **`final` classes** unless inheritance is explicitly needed (models are non-final for extension via registry)
- **Actions** are invokable, stateless, single-responsibility
- **Enums** are always string-backed
- **Models** use `protected $guarded = []`, method-syntax `casts()`, morph map aliases
- **Service providers** are `final`, register config in `register()`, load migrations and morph maps in `boot()`

## Commands

This package has no CLI commands of its own. The consuming project runs tests and linting.

## Adding a New Domain

1. Create `src/{Domain}/` with: service provider, config (with `models` key), contracts, concerns, models, migrations
2. Add the PSR-4 namespace to `composer.json` autoload
3. Add the service provider to `composer.json` `extra.laravel.providers`
4. Add a `README.md` to the domain directory
5. Update the dependency table in both this file and `README.md`
6. Ship a `Filament/{Domain}Schema.php` if the domain has form components
