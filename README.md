# In Other Shops

Modular e-commerce domain packages for Laravel 12.

## Installation

```bash
composer require jelte-ten-holt/in-other-shops
```

Service providers are auto-discovered via Laravel's package discovery.

## Domains

Each domain under `src/` is a self-contained package with its own service provider, migrations, config, contracts, and concerns. They are bundled together here for convenience, but are designed to be extracted into standalone Composer packages when the need arises.

| Domain | Purpose | Dependencies |
|---|---|---|
| **Currency** | Currency enum, formatting, config | — |
| **Translation** | Polymorphic translations table, locale management | — |
| **Location** | Address management (polymorphic) | — |
| **Media** | File attachments via morphToMany pivot | — |
| **Inventory** | Stock tracking, reservations, audit ledger | — |
| **Shipping** | Shipment model, shipping cost calculation | — |
| **Logging** | Domain event logging with pluggable handlers | — |
| **FlowChain** | Orchestrated multi-step business processes | — |
| **Pricing** | Prices, price lists, vouchers, tax | Currency |
| **Taxonomy** | Categories (hierarchical) and tags (flat, typed) | Translation |
| **Payment** | Gateway-agnostic payments, refunds, webhooks | Currency |
| **Commerce** | Cart, Order, Customer lifecycle | Location, Currency, Payment, Shipping |
| **Storefront** | Read-only API layer for browsable catalog | Currency, Pricing, Taxonomy, Translation, Media, Inventory |
| **Navigation** | Configurable menus | Planned |
| **Option** | Product options/variants | Planned |

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

## Extracting Individual Domains

Each domain is designed to become its own Composer package. When extracting:

1. Move the domain directory into its own repository
2. Give it its own `composer.json` with the `InOtherShops\{Domain}\` PSR-4 namespace
3. Add the domain's cross-domain dependencies as `require` entries
4. Remove it from this package and add the new package as a dependency instead

The inter-domain dependency table above documents what each domain needs.

## License

MIT
