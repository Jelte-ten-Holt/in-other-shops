<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Variants;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Inventory\Models\StockItem;
use InOtherShops\Variants\Filament\VariantsSchema;
use InOtherShops\Variants\Models\Option;
use InOtherShops\Variants\Models\Variant;
use InOtherShops\Tests\Stubs\TestVariantable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Covers the VariantsSchema manual-sync (declared axes + per-variant edits).
 * Filament rendering is not booted in this package's test layer.
 */
final class VariantsSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function its_form_components_build_without_error(): void
    {
        // Guards the Filament method chains — this package has no panel-booted
        // render layer, so construction is the cheapest available check.
        $this->assertNotNull(VariantsSchema::axesField());
        $this->assertNotNull(VariantsSchema::variantsRepeater());
    }

    #[Test]
    public function fill_loads_declared_axes_and_variant_rows(): void
    {
        $owner = TestVariantable::factory()->create();
        $option = Option::factory()->create();
        $owner->options()->attach($option->id, ['position' => 0]);
        $variant = Variant::factory()->for($owner, 'variantable')->create(['sku' => 'A']);
        $variant->prices()->create(['amount' => 2000, 'currency' => 'EUR', 'minimum_quantity' => 1]);

        $data = VariantsSchema::fillFormData($owner, []);

        $this->assertSame([$option->id], $data['_variant_options']);
        $this->assertSame('A', $data['_variants'][0]['sku']);
        $this->assertSame(2000, $data['_variants'][0]['price']);
    }

    #[Test]
    public function save_syncs_the_declared_axes(): void
    {
        $owner = TestVariantable::factory()->create();
        [$a, $b] = [Option::factory()->create(), Option::factory()->create()];
        $owner->options()->attach([$a->id, $b->id]);

        VariantsSchema::saveFormData($owner, ['_variant_options' => [$a->id], '_variants' => []]);

        $this->assertSame([$a->id], $owner->options()->pluck('options.id')->all());
    }

    #[Test]
    public function save_updates_variant_sku_and_price(): void
    {
        $owner = TestVariantable::factory()->create();
        $variant = Variant::factory()->for($owner, 'variantable')->create(['sku' => 'OLD']);

        VariantsSchema::saveFormData($owner, [
            '_variant_options' => [],
            '_variants' => [['id' => $variant->id, 'sku' => 'NEW', 'price' => 3300, 'stock' => 0]],
        ]);

        $variant->refresh();
        $this->assertSame('NEW', $variant->sku);
        $this->assertSame(3300, $variant->priceFor(Currency::EUR)?->amount);
    }

    #[Test]
    public function save_adjusts_variant_stock_through_the_ledger(): void
    {
        $owner = TestVariantable::factory()->create();
        $variant = Variant::factory()->for($owner, 'variantable')->create();
        StockItem::factory()->for($variant, 'stockable')->withLevel(5)->create();

        VariantsSchema::saveFormData($owner, [
            '_variant_options' => [],
            '_variants' => [['id' => $variant->id, 'sku' => null, 'price' => null, 'stock' => 8]],
        ]);

        $this->assertSame(8, $variant->fresh()->stockLevel());
        $this->assertSame(1, $variant->stockMovements()->count());
    }

    #[Test]
    public function save_deletes_variants_removed_from_the_repeater(): void
    {
        $owner = TestVariantable::factory()->create();
        $kept = Variant::factory()->for($owner, 'variantable')->create();
        $removed = Variant::factory()->for($owner, 'variantable')->create();

        VariantsSchema::saveFormData($owner, [
            '_variant_options' => [],
            '_variants' => [['id' => $kept->id, 'sku' => null, 'price' => null, 'stock' => 0]],
        ]);

        $this->assertModelExists($kept);
        $this->assertModelMissing($removed);
    }
}
