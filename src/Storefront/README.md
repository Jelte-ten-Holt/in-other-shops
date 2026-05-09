# Storefront Domain

Read-only catalog layer that exposes browsable items via Action classes. Composes multiple domains (Pricing, Taxonomy, Media, Translation) into a unified storefront experience.

> **HTTP layer is shelved** — the JSON-over-HTTP wrappers (controllers, dynamic-route loop, currency-context middleware) live on the [`storefront-http-archive` branch](https://github.com/Jelte-ten-Holt/in-other-shops/tree/storefront-http-archive) with passing tests. Main keeps the Actions, Contracts, JSON Resources (used by the Agent module's catalog tools as serializers), and the `StorefrontContext` DTO; consumers call Actions directly (e.g. via Inertia) or through the Agent tools. Resurrect the HTTP layer by merging the archive branch back when a consumer needs it.

## Architecture

### HasStorefrontPresence Contract & Trait

Any model can appear in the storefront by implementing `HasStorefrontPresence` and using the `InteractsWithStorefrontPresence` trait.

Models that want stock state in the storefront payload additionally implement `Storefront\Contracts\HasAvailability` (`stockLevel(): int` + `isInStock(): bool`). The package only exposes the boolean (`in_stock`) — exposing exact stock levels is a project decision (e.g. "only 2 left!" UI hints) and lives in the consuming project's own resources. The contract is intentionally minimal so that derived-stock models (e.g. bundles whose stock is computed from components) can implement it without needing a `stockItem` relationship like `Inventory\Contracts\HasStock` requires.

```php
interface HasStorefrontPresence
{
    public function getBrowsableName(): string;
    public function getBrowsableSlug(): string;
    public function getBrowsableDescription(): ?string;
    public function getBrowsableRouteKeyName(): string;
    public static function browseQuery(): Builder;
}
```

`InteractsWithStorefrontPresence` provides defaults: maps to `name`/`slug`/`description` attributes, `browseQuery()` scopes to `is_active = true` and `published_at <= now()`.

### Configuration

`config/storefront.php`:

```php
'models' => [
    'products' => \App\Models\Product::class,
],
'defaults' => [
    'per_page' => 24,
],
```

The `models` key maps storefront keys to model classes. Read by the Storefront Actions and the Agent module's catalog tools to resolve type → class.

### Automatic Eager Loading (`ResolvesEagerLoading`)

Actions inspect which domain contracts a model implements and automatically eager load the right relationships:

| Contract | Eager loads |
|---|---|
| `HasTranslations` | `translations` (filtered by locale) |
| `HasPrices` | `prices` |
| `HasCategories` | `categories`, `categories.translations` (filtered by locale) |
| `HasTags` | `tags`, `tags.translations` (filtered by locale) |
| `HasMedia` | `media` |

This prevents N+1 queries without the storefront knowing which domains a model uses.

### Actions

- **`ListBrowsables`** — lists items with filtering (category, tag, search), sorting (`name`, `created_at`, `published_at`, prefix with `-` for desc), and pagination. Returns `LengthAwarePaginator` of model instances; the consumer formats them.
- **`ListCategoryBrowsables`** — collects all browsable items across configured models that belong to a category. In-memory pagination.
- **`ShowBrowsable`** — retrieves a single item by slug.

### JSON Resources

Used by the Agent module's catalog tools (`browse_catalog`, `show_browsable`, `list_categories`, `list_tags`) to format models for tool output. The HTTP-layer archive branch reuses these — they're domain-level formatters, not HTTP-only.

- **`BrowsableResource`** — conditionally includes prices (resolved for `StorefrontContext` currency), `in_stock` (boolean), categories, and tags based on which contracts the model implements. Adds `type` metadata derived from `config('storefront.models')`.
- **`CategoryResource`** — category with nested children.
- **`PriceResource`** — raw amount + formatted string + compare-at price.
- **`TagResource`** — tag with type.

### StorefrontContext

`StorefrontContext` carries per-request currency for resources to use when resolving prices. The service provider binds a default that picks the first enabled `Currency`; HTTP-layer consumers (on the archive branch) override the binding via `SetStorefrontContext` middleware reading the `X-Currency` header.

## Dependencies

- **Currency** — for currency context resolution
- **Pricing** — for price resolution and formatting
- **Taxonomy** — for category/tag filtering and display
- **Translation** — for locale-aware eager loading
- **Media** — for media eager loading

## Future

- Reduce direct coupling to Taxonomy models (use contracts or config-driven model resolution)
