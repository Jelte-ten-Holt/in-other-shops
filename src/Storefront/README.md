# Storefront Domain

Read-only API layer that exposes browsable catalog items. Composes multiple domains (Pricing, Taxonomy, Media, Translation) into a unified storefront experience.

## Architecture

### Browsable Contract & Trait

Any model can appear in the storefront by implementing `Browsable` and using the `IsBrowsable` trait.

Models that want stock state in the storefront payload additionally implement `Storefront\Contracts\HasAvailability` (`stockLevel(): int` + `isInStock(): bool`). The package only exposes the boolean (`in_stock`) — exposing exact stock levels is a project decision (e.g. "only 2 left!" UI hints) and lives in the consuming project's own resources. The contract is intentionally minimal so that derived-stock models (e.g. bundles whose stock is computed from components) can implement it without needing a `stockItem` relationship like `Inventory\Contracts\HasStock` requires.

```php
interface Browsable
{
    public function getBrowsableName(): string;
    public function getBrowsableSlug(): string;
    public function getBrowsableDescription(): ?string;
    public function getBrowsableRouteKeyName(): string;
    public static function browseQuery(): Builder;
}
```

`IsBrowsable` provides defaults: maps to `name`/`slug`/`description` attributes, `browseQuery()` scopes to `is_active = true` and `published_at <= now()`.

### Configuration

`config/storefront.php`:

```php
'models' => [
    'products' => \App\Models\Product::class,
],
'defaults' => [
    'currency' => 'EUR',
    'per_page' => 24,
],
'prefix' => 'storefront',
'middleware' => ['api'],
```

The `models` key maps URL segments to model classes. Adding a new browsable type is a one-line config change.

### Automatic Eager Loading (`ResolvesEagerLoading`)

Actions inspect which domain contracts a model implements and automatically eager load the right relationships:

| Contract | Eager loads |
|---|---|
| `Translatable` | `translations` (filtered by locale) |
| `HasPrices` | `prices` |
| `HasCategories` | `categories`, `categories.translations` (filtered by locale) |
| `HasTags` | `tags`, `tags.translations` (filtered by locale) |
| `HasMedia` | `media` |

This prevents N+1 queries without the storefront knowing which domains a model uses.

### Currency Context

`SetStorefrontContext` middleware reads the `X-Currency` request header, validates it against enabled currencies, and registers a `StorefrontContext` DTO in the service container. Resources use this to resolve the correct price.

### Routes

Registered dynamically under `api/storefront/` from configured models:

- `GET /api/storefront/{type}` — list browsables (paginated, filterable, sortable)
- `GET /api/storefront/{type}/{slug}` — show single browsable
- `GET /api/storefront/categories` — list root categories with children
- `GET /api/storefront/categories/{slug}` — show category with paginated browsable items

### Actions

- **`ListBrowsables`** — lists items with filtering (category, tag, search), sorting (`name`, `created_at`, `published_at`, prefix with `-` for desc), and pagination.
- **`ListCategoryBrowsables`** — collects all browsable items across configured models that belong to a category. In-memory pagination.
- **`ShowBrowsable`** — retrieves a single item by slug.

### JSON Resources

- **`BrowsableResource`** — conditionally includes prices (resolved for context currency), `in_stock` (boolean), categories, and tags based on which contracts the model implements. Adds `type` metadata.
- **`CategoryResource`** — category with nested children.
- **`PriceResource`** — raw amount + formatted string + compare-at price.
- **`TagResource`** — tag with type.

## Dependencies

- **Currency** — for currency context resolution
- **Pricing** — for price resolution and formatting
- **Taxonomy** — for category/tag filtering and display
- **Translation** — for locale-aware eager loading
- **Media** — for media eager loading

## Future

- Reduce direct coupling to Taxonomy models (use contracts or config-driven model resolution)
