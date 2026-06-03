<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce;

use InOtherShops\Commerce\Filament\CommerceSchema;
use InOtherShops\Tests\Stubs\TestCartable;
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
}
