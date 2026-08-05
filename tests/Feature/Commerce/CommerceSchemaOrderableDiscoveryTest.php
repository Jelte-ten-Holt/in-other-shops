<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce;

use InOtherShops\Commerce\Filament\CommerceSchema;
use InOtherShops\Tests\Stubs\TestCartable;
use InOtherShops\Tests\Stubs\TestTranslatableCartable;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The order-line picker discovers orderable models from the morph map (any
 * HasOrders implementer) rather than a passed-in list, and keys options by
 * `{alias}:{id}` so two morph types sharing an integer id never collide — the
 * pre-existing bare-id collision in the old picker.
 */
final class CommerceSchemaOrderableDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function discovers_orderable_models_from_the_morph_map(): void
    {
        $models = CommerceSchema::discoverOrderableModels();

        $this->assertArrayHasKey('test_cartable', $models);
        $this->assertSame(TestCartable::class, $models['test_cartable']);
    }

    #[Test]
    public function does_not_discover_a_non_orderable_model(): void
    {
        // test_stockable implements HasStock but not HasOrders.
        $models = CommerceSchema::discoverOrderableModels();

        $this->assertArrayNotHasKey('test_stockable', $models);
    }

    #[Test]
    public function builds_type_qualified_option_keys(): void
    {
        $item = TestCartable::factory()->create(['name' => 'Boxed Set']);

        $options = CommerceSchema::buildOrderableOptions(
            CommerceSchema::discoverOrderableModels(),
        );

        $this->assertSame('Boxed Set', $options["test_cartable:{$item->id}"]);
    }

    /**
     * The regression this suite could not previously express: every other
     * orderable stub has a real `name` column, so the old `pluck('name', 'id')`
     * passed here while 500ing on a consumer whose catalog is translation-backed
     * ("Unknown column 'name' in 'field list'" on every order page).
     */
    #[Test]
    public function labels_a_translation_backed_orderable_that_has_no_name_column(): void
    {
        $item = TestTranslatableCartable::factory()->create(['slug' => 'agate-choker']);
        $item->setTranslation('name', 'en', 'Blue Lace Agate Choker');

        $options = CommerceSchema::buildOrderableOptions(
            CommerceSchema::discoverOrderableModels(),
        );

        $this->assertSame(
            'Blue Lace Agate Choker',
            $options["test_translatable_cartable:{$item->id}"],
        );
    }

    /**
     * A half-authored record must still be pickable. Returning an empty label
     * renders a blank, unselectable row in the Filament option list; returning
     * null violates the array<string, string> contract.
     */
    #[Test]
    public function falls_back_to_the_slug_when_no_name_translation_exists(): void
    {
        $item = TestTranslatableCartable::factory()->create(['slug' => 'untitled-piece']);

        $options = CommerceSchema::buildOrderableOptions(
            CommerceSchema::discoverOrderableModels(),
        );

        $this->assertSame('untitled-piece', $options["test_translatable_cartable:{$item->id}"]);
    }

    /**
     * Both catalog shapes appear in one picker — the realistic case, since a
     * consumer may register several orderables with different storage.
     */
    #[Test]
    public function builds_options_across_mixed_catalog_shapes(): void
    {
        $columnBacked = TestCartable::factory()->create(['name' => 'Boxed Set']);
        $translationBacked = TestTranslatableCartable::factory()->create(['slug' => 'choker']);
        $translationBacked->setTranslation('name', 'en', 'Choker');

        $options = CommerceSchema::buildOrderableOptions(
            CommerceSchema::discoverOrderableModels(),
        );

        $this->assertSame('Boxed Set', $options["test_cartable:{$columnBacked->id}"]);
        $this->assertSame('Choker', $options["test_translatable_cartable:{$translationBacked->id}"]);
    }
}
