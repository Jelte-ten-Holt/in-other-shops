<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs;

use InOtherShops\Tax\Enums\TaxCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * One factory for every stub, replacing the 14 per-stub factories.
 *
 * The stub model is carried on the instance (set by {@see StubModel::newFactory()}
 * via {@see ofModel()}) and preserved across state chaining in {@see newInstance()},
 * so `TestCartable::factory()->create([...])` resolves the right class. Defaults
 * are composed from the model's `capabilities()` — the same list that builds its
 * columns — so a factory default can never reference a column the table lacks.
 *
 * @extends Factory<StubModel>
 */
final class StubModelFactory extends Factory
{
    /** @var class-string<StubModel> */
    private string $stubModel = StubModel::class;

    /**
     * @param  class-string<StubModel>  $model
     */
    public static function ofModel(string $model): self
    {
        $factory = self::new();
        $factory->stubModel = $model;

        return $factory;
    }

    public function modelName(): string
    {
        return $this->stubModel;
    }

    public function definition(): array
    {
        $capabilities = $this->stubModel::capabilities();

        $attributes = [];
        foreach ($capabilities as $capability) {
            $attributes = array_merge($attributes, $this->defaultsFor($capability));
        }

        // Storefront slug is derived from the generated name so it stays unique.
        if (in_array('storefront', $capabilities, true)) {
            $attributes['slug'] = Str::slug((string) ($attributes['name'] ?? ''));
        }

        return $attributes;
    }

    /** Untracked stockable — stock movements are ignored, `isInStock()` is always true. */
    public function untracked(): self
    {
        return $this->state(['tracks_stock' => false]);
    }

    /** Sellable past its stock level. */
    public function backorderable(): self
    {
        return $this->state(['allow_backorder' => true]);
    }

    protected function newInstance(array $arguments = []): static
    {
        $instance = parent::newInstance($arguments);
        $instance->stubModel = $this->stubModel;

        return $instance;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultsFor(string $capability): array
    {
        return match ($capability) {
            'identity' => ['name' => fake()->unique()->words(3, true)],
            'describable' => ['description' => fake()->sentence()],
            'storefront' => ['published_at' => now()],
            'flatPrice' => ['unit_price' => 1500],
            'preOrder' => ['is_pre_order' => false, 'expected_ship_date' => null],
            'stock' => ['tracks_stock' => true, 'allow_backorder' => false],
            'localeGroup' => ['locale' => 'en'],
            'localizableTitle' => ['title' => fake()->sentence(), 'slug' => fake()->unique()->slug()],
            'payments' => ['total_due' => 1000],
            'taxCategory' => ['tax_category' => TaxCategory::PhysicalGoods->value],
            'shippability' => ['requires_shipping' => true],
            'purchasing' => ['sku' => strtoupper(fake()->bothify('??-####'))],
            'translations' => ['slug' => fake()->unique()->slug()],
            default => [],
        };
    }
}
