<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Purchasing;

use InOtherShops\Purchasing\Filament\PurchasingSchema;
use InOtherShops\Tests\Stubs\TestPurchasable;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Pins the contract-discovery picker: a model appears in the purchase-line
 * picker purely by implementing HasPurchases + being in the morph map (no
 * per-schema registration), and options are keyed `{alias}:{id}` so two morph
 * types sharing an integer id can never collide.
 */
final class PurchasingSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function discovers_purchasable_models_from_the_morph_map(): void
    {
        $models = PurchasingSchema::discoverPurchasableModels();

        $this->assertArrayHasKey('test_purchasable', $models);
        $this->assertSame(TestPurchasable::class, $models['test_purchasable']);
    }

    #[Test]
    public function does_not_discover_a_stockable_that_is_not_purchasable(): void
    {
        // test_stockable implements HasStock but NOT HasPurchases.
        $models = PurchasingSchema::discoverPurchasableModels();

        $this->assertArrayNotHasKey('test_stockable', $models);
    }

    #[Test]
    public function builds_type_qualified_option_keys(): void
    {
        $a = TestPurchasable::factory()->create(['name' => 'The Hobbit']);
        $b = TestPurchasable::factory()->create(['name' => 'Dune']);

        $options = PurchasingSchema::buildPurchasableOptions(
            PurchasingSchema::discoverPurchasableModels(),
        );

        $this->assertSame('The Hobbit', $options["test_purchasable:{$a->id}"]);
        $this->assertSame('Dune', $options["test_purchasable:{$b->id}"]);
    }
}
