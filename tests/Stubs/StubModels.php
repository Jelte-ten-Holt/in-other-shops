<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs;

use InOtherShops\Commerce\Cart\Concerns\InteractsWithCart;
use InOtherShops\Commerce\Cart\Contracts\HasCart;
use InOtherShops\Commerce\Order\Contracts\HasOrders;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Inventory\Concerns\InteractsWithStock;
use InOtherShops\Inventory\Contracts\HasStock;
use InOtherShops\Media\Concerns\InteractsWithMedia;
use InOtherShops\Media\Contracts\HasMedia;
use InOtherShops\Payment\Concerns\InteractsWithPayments;
use InOtherShops\Payment\Contracts\HasPayments;
use InOtherShops\Pricing\Concerns\InteractsWithPrices;
use InOtherShops\Pricing\Contracts\HasPrices;
use InOtherShops\Purchasing\Concerns\InteractsWithPurchasing;
use InOtherShops\Purchasing\Contracts\HasPurchases;
use InOtherShops\Shipping\Contracts\HasShippability;
use InOtherShops\Storefront\Contracts\HasStorefrontPresence;
use InOtherShops\Tax\Contracts\HasTaxCategory;
use InOtherShops\Tax\Enums\TaxCategory;
use InOtherShops\Taxonomy\Concerns\InteractsWithCategories;
use InOtherShops\Taxonomy\Concerns\InteractsWithTags;
use InOtherShops\Taxonomy\Contracts\HasCategories;
use InOtherShops\Taxonomy\Contracts\HasTags;
use InOtherShops\Translation\Concerns\InteractsWithLocaleGroup;
use InOtherShops\Translation\Concerns\InteractsWithTranslations;
use InOtherShops\Translation\Contracts\HasLocaleGroup;
use InOtherShops\Translation\Contracts\HasTranslations;
use InOtherShops\Variants\Concerns\InteractsWithVariants;
use InOtherShops\Variants\Contracts\HasVariants;
use Illuminate\Database\Eloquent\Builder;

/*
 * The 16 test stubs. Class names are unchanged from the pre-consolidation
 * one-file-per-stub layout so every test file and the morph map keep resolving.
 * Each subclass carries only what a trait can't supply: its contract set, its
 * traits, its table, its capability list, and the handful of contract methods
 * that read a column (`tracksStock`, `getCartableUnitPrice`, `taxCategory`, …).
 *
 * This file holds multiple classes, so it is autoloaded via composer's
 * `autoload-dev.classmap`, not PSR-4.
 */

/** `HasStock`, `HasStorefrontPresence`. */
final class TestBrowsable extends StubModel implements HasStock, HasStorefrontPresence
{
    use InteractsWithStock;

    protected $table = 'test_browsables';

    public static function capabilities(): array
    {
        return ['identity', 'describable', 'storefront', 'stock'];
    }

    public function tracksStock(): bool
    {
        return (bool) $this->tracks_stock;
    }

    public function getBrowsableName(): string
    {
        return (string) $this->name;
    }

    public function getBrowsableSlug(): string
    {
        return (string) $this->slug;
    }

    public function getBrowsableDescription(): ?string
    {
        return $this->description;
    }

    public function getBrowsableRouteKeyName(): string
    {
        return 'slug';
    }

    public static function browseQuery(): Builder
    {
        return static::query();
    }
}

/** `HasCart`, `HasOrders` — priced from a flat `unit_price` column. */
final class TestCartable extends StubModel implements HasCart, HasOrders
{
    use InteractsWithCart;

    protected $table = 'test_cartables';

    public static function capabilities(): array
    {
        return ['identity', 'describable', 'flatPrice', 'preOrder'];
    }

    public function getCartableUnitPrice(Currency $currency): ?int
    {
        return $this->unit_price;
    }

    public function toOrderLineData(string $currencyCode): array
    {
        return [
            'description' => 'Test cartable #'.$this->getKey(),
            'sku' => null,
            'currency' => $currencyCode,
            'unit_price' => $this->unit_price ?? 0,
            'is_pre_order' => (bool) $this->is_pre_order,
            'expected_ship_date' => $this->expected_ship_date instanceof \DateTimeInterface
                ? $this->expected_ship_date->format('Y-m-d')
                : $this->expected_ship_date,
        ];
    }

    public function availableCurrencies(): array
    {
        return [Currency::EUR->value];
    }
}

/**
 * `HasStorefrontPresence`, `HasTranslations` — the second catalog shape, browsable.
 *
 * The storefront counterpart to {@see TestTranslatableCartable}: no `name` and
 * no `description` column, both resolved from the `translations` table. Only
 * the `storefront` capability is declared — it already supplies `slug`, and
 * `translations` would collide on that same column.
 *
 * Exists so {@see \InOtherShops\Storefront\Actions\ListBrowsables} search and
 * sort are exercised against a catalog whose text is not in columns. Against
 * {@see TestBrowsable} alone, `where('name', 'like', …)` looks perfectly fine.
 */
final class TestTranslatableBrowsable extends StubModel implements HasStorefrontPresence, HasTranslations
{
    use InteractsWithTranslations;

    protected $table = 'test_translatable_browsables';

    public static function capabilities(): array
    {
        return ['storefront'];
    }

    /** @return array<string> */
    public function translatableFields(): array
    {
        return ['name', 'description'];
    }

    public function getBrowsableName(): string
    {
        return (string) $this->name;
    }

    public function getBrowsableSlug(): string
    {
        return (string) $this->slug;
    }

    public function getBrowsableDescription(): ?string
    {
        return $this->description;
    }

    public function getBrowsableRouteKeyName(): string
    {
        return 'slug';
    }

    public static function browseQuery(): Builder
    {
        return static::query();
    }
}

/**
 * `HasCart`, `HasOrders`, `HasTranslations` — the second catalog shape.
 *
 * Deliberately has NO `name` column: its name and description live in the
 * `translations` table and surface only through the accessor
 * `InteractsWithTranslations::getAttribute()` installs. This is the bianka
 * consumer's shape, and its absence from the stub set is exactly why the order
 * admin shipped a `pluck('name', 'id')` that 500'd on every order page while
 * 1055 tests stayed green — every other orderable stub declares `identity`,
 * so a column-level read could never fail here.
 *
 * Any package code that resolves a label, searches, or sorts a consumer model
 * should be exercised against BOTH this and {@see TestCartable}.
 */
final class TestTranslatableCartable extends StubModel implements HasCart, HasOrders, HasTranslations
{
    use InteractsWithCart;
    use InteractsWithTranslations;

    protected $table = 'test_translatable_cartables';

    public static function capabilities(): array
    {
        return ['translations', 'flatPrice'];
    }

    /** @return array<string> */
    public function translatableFields(): array
    {
        return ['name', 'description'];
    }

    public function getCartableUnitPrice(Currency $currency): ?int
    {
        return $this->unit_price;
    }

    public function toOrderLineData(string $currencyCode): array
    {
        return [
            'description' => $this->translated('name') ?? $this->slug,
            'sku' => null,
            'currency' => $currencyCode,
            'unit_price' => $this->unit_price ?? 0,
        ];
    }

    public function availableCurrencies(): array
    {
        return [Currency::EUR->value];
    }
}

/** `HasLocaleGroup` — a translatable-title row in a locale group. */
final class TestLocalizable extends StubModel implements HasLocaleGroup
{
    use InteractsWithLocaleGroup;

    protected $table = 'test_localizables';

    public static function capabilities(): array
    {
        return ['localeGroup', 'localizableTitle'];
    }
}

/** `HasMedia`. */
final class TestMediable extends StubModel implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'test_mediables';

    public static function capabilities(): array
    {
        return ['identity'];
    }
}

/** `HasPayments`. */
final class TestPayable extends StubModel implements HasPayments
{
    use InteractsWithPayments;

    protected $table = 'test_payables';

    public static function capabilities(): array
    {
        return ['payments'];
    }

    public function getPaymentTotalDue(): int
    {
        return (int) $this->total_due;
    }
}

/** `HasPrices`. */
final class TestPriceable extends StubModel implements HasPrices
{
    use InteractsWithPrices;

    protected $table = 'test_priceables';

    public static function capabilities(): array
    {
        return ['identity'];
    }
}

/** `HasPurchases` (extends `HasStock`). */
final class TestPurchasable extends StubModel implements HasPurchases
{
    use InteractsWithPurchasing;
    use InteractsWithStock;

    protected $table = 'test_purchasables';

    public static function capabilities(): array
    {
        return ['identity', 'purchasing', 'stock'];
    }

    public function tracksStock(): bool
    {
        return (bool) $this->tracks_stock;
    }
}

/** `HasCart`, `HasShippability`, `HasTaxCategory` — priced from `unit_price`. */
final class TestShippableCartable extends StubModel implements HasCart, HasShippability, HasTaxCategory
{
    use InteractsWithCart;

    protected $table = 'test_shippable_cartables';

    public static function capabilities(): array
    {
        return ['identity', 'describable', 'flatPrice', 'shippability', 'taxCategory'];
    }

    public function getCartableUnitPrice(Currency $currency): ?int
    {
        return $this->unit_price;
    }

    public function requiresShipping(): bool
    {
        return (bool) $this->requires_shipping;
    }

    public function taxCategory(): TaxCategory
    {
        return $this->tax_category;
    }
}

/** `HasStock`. */
final class TestStockable extends StubModel implements HasStock
{
    use InteractsWithStock;

    protected $table = 'test_stockables';

    public static function capabilities(): array
    {
        return ['identity', 'stock'];
    }

    public function tracksStock(): bool
    {
        return (bool) $this->tracks_stock;
    }
}

/**
 * `HasCart`, `HasStock` — exercises the soft Commerce → Inventory dependency
 * that `EnsureCartableInStock` relies on. `tracks_stock` + `allow_backorder`
 * are settable per row (via columns / the factory's `untracked()` /
 * `backorderable()` states) so one test file covers all four guard branches.
 */
final class TestStockableCartable extends StubModel implements HasCart, HasStock
{
    use InteractsWithCart;
    use InteractsWithStock;

    protected $table = 'test_stockable_cartables';

    public static function capabilities(): array
    {
        return ['identity', 'describable', 'flatPrice', 'stock'];
    }

    public function getCartableUnitPrice(Currency $currency): ?int
    {
        return $this->unit_price;
    }

    public function tracksStock(): bool
    {
        return (bool) $this->tracks_stock;
    }
}

/** `HasLocaleGroup`, `HasStock`. */
final class TestStockableLocalizable extends StubModel implements HasLocaleGroup, HasStock
{
    use InteractsWithLocaleGroup;
    use InteractsWithStock;

    protected $table = 'test_stockable_localizables';

    public static function capabilities(): array
    {
        return ['identity', 'localeGroup', 'stock'];
    }

    public function tracksStock(): bool
    {
        return (bool) $this->tracks_stock;
    }
}

/** `HasCategories`, `HasTags`. */
final class TestTaxonomized extends StubModel implements HasCategories, HasTags
{
    use InteractsWithCategories;
    use InteractsWithTags;

    protected $table = 'test_taxonomizeds';

    public static function capabilities(): array
    {
        return ['identity'];
    }
}

/** `HasTranslations`. */
final class TestTranslatable extends StubModel implements HasTranslations
{
    use InteractsWithTranslations;

    protected $table = 'test_translatables';

    public static function capabilities(): array
    {
        return ['translations'];
    }

    /** @return array<string> */
    public function translatableFields(): array
    {
        return ['name', 'description'];
    }
}

/**
 * A product-shaped variant owner: cart-able and priced/stocked in its own right
 * (as a flat owner would be), and able to own variants. Exercises the price
 * template copy, stock carry, and cart-line label composition. Priced via
 * `HasPrices` (not a flat `unit_price`), so no `flatPrice` capability.
 */
final class TestVariantable extends StubModel implements HasCart, HasPrices, HasStock, HasVariants
{
    use InteractsWithCart;
    use InteractsWithPrices;
    use InteractsWithStock;
    use InteractsWithVariants;

    protected $table = 'test_variantables';

    public static function capabilities(): array
    {
        return ['identity', 'stock'];
    }

    public function tracksStock(): bool
    {
        return (bool) $this->tracks_stock;
    }
}

/**
 * `HasStock`, `HasMedia`, `HasTranslations` — the consumer-catalogue shape that
 * {@see \InOtherShops\Tests\Stubs\Filament\StubEditableResource} edits through
 * real Filament pages, so the manual-sync Schemas are exercised across a page
 * lifecycle and not only through their static halves.
 */
final class TestEditable extends StubModel implements HasMedia, HasStock, HasTranslations
{
    use InteractsWithMedia;
    use InteractsWithStock;
    use InteractsWithTranslations;

    protected $table = 'test_editables';

    public static function capabilities(): array
    {
        return ['stock', 'translations'];
    }

    /** @return array<string> */
    public function translatableFields(): array
    {
        return ['name'];
    }

    public function tracksStock(): bool
    {
        return (bool) $this->tracks_stock;
    }
}
