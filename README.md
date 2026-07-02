# In Other Shops

Modular e-commerce domain packages for Laravel 12+.

## Installation

```bash
composer require jelte-ten-holt/in-other-shops
```

Service providers are auto-discovered via Laravel's package discovery.

## Domains

Each domain under `src/` has its own service provider, migrations, config, contracts, and concerns. This is a **modular monolith** — one package with clean internal domain boundaries — not a staging ground for per-domain packages. See `CLAUDE.md` for the four-tier structure (generic leaves / middle band / shop core / integration) and the extraction policy.

Grouped by tier (see `CLAUDE.md` for what the tiers mean).

| Tier | Domain | Purpose | Dependencies |
|---|---|---|---|
| Leaf | **Currency** | Currency enum, formatting, config | — |
| Leaf | **Translation** | Polymorphic translations table, locale management | — |
| Leaf | **Location** | Address management (polymorphic) | — |
| Leaf | **Media** | File attachments via morphToMany pivot | — |
| Leaf | **Logging** | Domain event logging with pluggable handlers | — |
| Leaf | **FlowChain** | Orchestrated multi-step business processes | — |
| Leaf | **Support** | Shared kernel: base service provider, Filament bases, state transitions | Currency |
| Middle | **Pricing** | Prices, price lists, vouchers | Currency |
| Middle | **Taxonomy** | Categories (hierarchical) and tags (flat, typed) | Translation, Media |
| Middle | **Payment** | Gateway-agnostic payments, refunds, webhooks | Currency |
| Middle | **Tax** | VAT rates and calculation | Location |
| Middle | **Inventory** | Stock tracking, reservations, audit ledger | (Translation — drift) |
| Shop core | **Commerce** | Cart, Order, Customer lifecycle | Currency, Location, Payment, Pricing, Shipping, Tax, FlowChain, Inventory |
| Shop core | **Shipping** | Shipment model, shipping cost calculation | Currency, Location, Commerce (cycle — accepted) |
| Shop core | **Purchasing** | Inbound inventory / purchase orders | Inventory, Tax |
| Integration | **Storefront** | Read-only API layer for browsable catalog | Currency, Inventory, Media, Pricing, Taxonomy, Translation |
| Integration | **Variants** | Product options/values and purchasable variants | Commerce, Inventory, Media, Pricing, Translation |
| Integration | **Agent** | MCP Streamable HTTP endpoint (bearer or OAuth 2.1 + DCR) | Commerce, Inventory, Storefront, Taxonomy |
| Planned | **Navigation** | Configurable menus | — |

## Usage

Project models opt into domain capabilities by implementing contracts and using traits:

```php
use InOtherShops\Pricing\Contracts\HasPrices;
use InOtherShops\Pricing\Concerns\InteractsWithPrices;
use InOtherShops\Media\Contracts\HasMedia;
use InOtherShops\Media\Concerns\InteractsWithMedia;

class Product extends Model implements HasPrices, HasMedia
{
    use InteractsWithPrices;
    use InteractsWithMedia;
}
```

Each domain ships config with a `models` key for overriding model classes via the registry pattern. Publish config to customize:

```php
// config/pricing.php
return [
    'models' => [
        'price' => App\Models\CustomPrice::class,
    ],
];
```

## Extraction Policy

This package is a modular monolith, not a set of packages-in-waiting. Extraction is **on-demand and leaf-only**: split a generic leaf (Currency, Logging, Media, Translation, Location, FlowChain, Support) into its own lower-level Composer package **only** when a concrete non-shop consumer needs it without the rest of the shop. The middle band, shop core, and integration tier are not extraction targets — the shop core (Commerce/Shipping/Purchasing) moves as one unit if it ever moves at all.

Don't split speculatively. The migrations-run-in-every-consumer cost that used to motivate splitting is handled by the per-domain `{domain}.migrations.enabled` config gate, not by extraction.

If a leaf extraction ever happens: move the directory to its own repo, give it a `composer.json` with the `InOtherShops\{Domain}\` PSR-4 namespace and its (leaf-only) dependencies as `require` entries, and depend on the new package here. Note that Logging is consumed by nearly every domain, so it would need to publish first.

## License

MIT
