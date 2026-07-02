<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs;

use InOtherShops\Tax\Enums\TaxCategory;
use Illuminate\Database\Schema\Blueprint;

/**
 * Per-capability schema + cast fragments for the test stubs.
 *
 * {@see apply()} composes a stub's table from its capability list; {@see castsFor()}
 * gives {@see StubModel::casts()} the matching Eloquent casts. Keeping the column
 * and the cast for a capability side by side is what stops the two from drifting.
 *
 * An unknown capability throws `UnhandledMatchError` — a fail-loud tripwire for a
 * stub that declares a capability with no fragment behind it.
 */
final class StubColumns
{
    /**
     * @param  list<string>  $capabilities
     */
    public static function apply(Blueprint $table, array $capabilities): void
    {
        $table->id();

        foreach ($capabilities as $capability) {
            self::columnsFor($table, $capability);
        }

        $table->timestamps();
    }

    /**
     * @return array<string, string>
     */
    public static function castsFor(string $capability): array
    {
        return match ($capability) {
            'storefront' => ['published_at' => 'datetime'],
            'flatPrice' => ['unit_price' => 'integer'],
            'preOrder' => ['is_pre_order' => 'boolean', 'expected_ship_date' => 'date'],
            'stock' => ['tracks_stock' => 'boolean', 'allow_backorder' => 'boolean'],
            'payments' => ['total_due' => 'integer'],
            'taxCategory' => ['tax_category' => TaxCategory::class],
            'shippability' => ['requires_shipping' => 'boolean'],
            default => [],
        };
    }

    private static function columnsFor(Blueprint $table, string $capability): void
    {
        match ($capability) {
            'identity' => $table->string('name'),
            'describable' => $table->text('description')->nullable(),
            'storefront' => self::storefront($table),
            'flatPrice' => $table->integer('unit_price')->nullable()->default(1500),
            'preOrder' => self::preOrder($table),
            'stock' => self::stock($table),
            'localeGroup' => self::localeGroup($table),
            'localizableTitle' => self::localizableTitle($table),
            'payments' => $table->integer('total_due')->default(0),
            'taxCategory' => $table->string('tax_category')->default('physical_goods'),
            'shippability' => $table->boolean('requires_shipping')->default(true),
            'purchasing' => $table->string('sku')->nullable(),
            'translations' => $table->string('slug'),
        };
    }

    private static function storefront(Blueprint $table): void
    {
        $table->string('slug')->unique();
        $table->timestamp('published_at')->nullable();
    }

    private static function preOrder(Blueprint $table): void
    {
        $table->boolean('is_pre_order')->default(false);
        $table->date('expected_ship_date')->nullable();
    }

    private static function stock(Blueprint $table): void
    {
        $table->boolean('tracks_stock')->default(true);
        $table->boolean('allow_backorder')->default(false);
    }

    private static function localeGroup(Blueprint $table): void
    {
        $table->foreignId('locale_group_id')->nullable()->constrained()->nullOnDelete();
        $table->string('locale', 10)->nullable();
        $table->unique(['locale_group_id', 'locale']);
    }

    private static function localizableTitle(Blueprint $table): void
    {
        $table->string('title');
        $table->string('slug');
        // Composite unique spans this fragment's `slug` and the localeGroup
        // fragment's `locale`; both stubs that use it also carry localeGroup.
        $table->unique(['slug', 'locale']);
    }
}
