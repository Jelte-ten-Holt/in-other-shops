<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Variants;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InOtherShops\Commerce\Cart\Models\CartItem;
use InOtherShops\Inventory\Models\StockItem;
use InOtherShops\Inventory\Models\StockMovement;
use InOtherShops\Inventory\DTOs\Stock;
use InOtherShops\Media\Models\Media;
use InOtherShops\Media\Models\Mediable;
use InOtherShops\Pricing\Models\Price;
use InOtherShops\Tests\Stubs\TestPriceable;
use InOtherShops\Tests\TestCase;
use InOtherShops\Variants\Exceptions\VariantStillReferencedException;
use PHPUnit\Framework\Attributes\Test;

/**
 * The package's first drop migration (complexity cleanup release 2, PR 4). A
 * consumer that ran the old create migrations has the three tables and maybe
 * rows a variant owned; the suite migrates fresh, so each test builds the old
 * tables by hand in their shipped shape, then runs the migration's `up()`.
 */
final class DropVariantTablesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const string MIGRATION = __DIR__.'/../../../src/Variants/Database/Migrations/2026_11_08_000002_drop_variant_tables.php';

    #[Test]
    public function it_deletes_what_variants_owned_and_drops_the_tables(): void
    {
        $this->createShippedVariantTables();
        $variantId = DB::table('variants')->insertGetId(['variantable_type' => 'product', 'variantable_id' => 1, 'sku' => 'A', 'position' => 0]);

        $variantPrice = Price::factory()->create(['priceable_type' => 'variant', 'priceable_id' => $variantId]);
        $otherPrice = TestPriceable::factory()->create()->prices()->create(['currency' => 'EUR', 'amount' => 500, 'minimum_quantity' => 1]);

        $variantStock = StockItem::factory()->create(['stockable_type' => 'variant', 'stockable_id' => $variantId, 'stock_level' => new Stock(3)]);
        $movement = StockMovement::factory()->create(['stock_item_id' => $variantStock->id]);

        $onlyTheVariants = Media::factory()->create();
        Mediable::factory()->create(['media_id' => $onlyTheVariants->id, 'mediable_type' => 'variant', 'mediable_id' => $variantId]);
        $shared = Media::factory()->create();
        Mediable::factory()->create(['media_id' => $shared->id, 'mediable_type' => 'variant', 'mediable_id' => $variantId]);
        Mediable::factory()->create(['media_id' => $shared->id, 'mediable_type' => 'option_value', 'mediable_id' => 7]);

        $this->migration()->up();

        $this->assertFalse(Schema::hasTable('variants'));
        $this->assertFalse(Schema::hasTable('option_value_variant'));
        $this->assertFalse(Schema::hasTable('optionables'));

        $this->assertModelMissing($variantPrice);
        $this->assertModelExists($otherPrice);
        $this->assertModelMissing($variantStock);
        $this->assertModelMissing($movement);
        $this->assertModelMissing($onlyTheVariants);
        $this->assertModelExists($shared);
        $this->assertSame(0, Mediable::query()->where('mediable_type', 'variant')->count());
        $this->assertSame(1, Mediable::query()->where('media_id', $shared->id)->count());
    }

    /**
     * The alias is gone after this release and the morph map is required, so a
     * line pointing at a variant could no longer load. The migration stops
     * with every table and row where it was.
     */
    #[Test]
    public function it_refuses_while_a_cart_line_points_at_a_variant_and_changes_nothing(): void
    {
        $this->createShippedVariantTables();
        $variantId = DB::table('variants')->insertGetId(['variantable_type' => 'product', 'variantable_id' => 1, 'sku' => 'A', 'position' => 0]);
        $price = Price::factory()->create(['priceable_type' => 'variant', 'priceable_id' => $variantId]);
        CartItem::factory()->create(['cartable_type' => 'variant', 'cartable_id' => $variantId]);

        try {
            $this->migration()->up();
            $this->fail('The migration ran while a cart line pointed at a variant.');
        } catch (VariantStillReferencedException $e) {
            $this->assertStringContainsString('1 in cart_items', $e->getMessage());
        }

        $this->assertTrue(Schema::hasTable('variants'));
        $this->assertTrue(Schema::hasTable('option_value_variant'));
        $this->assertModelExists($price);
    }

    #[Test]
    public function a_fresh_install_or_a_second_run_does_nothing(): void
    {
        $this->assertFalse(Schema::hasTable('variants'), 'a fresh install never had the table');

        $this->migration()->up();
        $this->migration()->up();

        $this->assertFalse(Schema::hasTable('variants'));
        $this->assertTrue(Schema::hasTable('options'), 'the Options half stays');
        $this->assertTrue(Schema::hasTable('option_values'));
    }

    private function migration(): Migration
    {
        return require self::MIGRATION;
    }

    /** The three tables as the deleted create migrations shipped them. */
    private function createShippedVariantTables(): void
    {
        Schema::create('variants', function (Blueprint $table): void {
            $table->id();
            $table->morphs('variantable');
            $table->string('sku')->nullable()->unique();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('option_value_variant', function (Blueprint $table): void {
            $table->foreignId('variant_id')->constrained('variants')->cascadeOnDelete();
            $table->foreignId('option_value_id')->constrained('option_values')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['variant_id', 'option_value_id'], 'option_value_variant_unique');
        });

        Schema::create('optionables', function (Blueprint $table): void {
            $table->foreignId('option_id')->constrained('options')->cascadeOnDelete();
            $table->morphs('optionable');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['option_id', 'optionable_type', 'optionable_id'], 'optionables_unique');
        });
    }
}
