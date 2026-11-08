<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Variants;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InOtherShops\Commerce\Cart\Models\CartItem;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Commerce\Order\Models\OrderLine;
use InOtherShops\Inventory\Models\StockItem;
use InOtherShops\Inventory\Models\StockMovement;
use InOtherShops\Inventory\DTOs\Stock;
use InOtherShops\Media\Models\Media;
use InOtherShops\Media\Models\Mediable;
use InOtherShops\Pricing\Models\Price;
use InOtherShops\Purchasing\Models\PurchaseOrderLine;
use InOtherShops\Taxonomy\Models\Category;
use InOtherShops\Taxonomy\Models\Tag;
use InOtherShops\Tests\Stubs\TestPriceable;
use InOtherShops\Tests\TestCase;
use InOtherShops\Variants\Exceptions\VariantStillReferencedException;
use PHPUnit\Framework\Attributes\DataProvider;
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

        $variantPrice = Price::factory()->createQuietly(['priceable_type' => 'variant', 'priceable_id' => $variantId]);
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
    #[DataProvider('linesThatPointAtAVariant')]
    public function it_refuses_while_a_line_points_at_a_variant_and_changes_nothing(string $table, string $morph): void
    {
        $this->createShippedVariantTables();
        $variantId = DB::table('variants')->insertGetId(['variantable_type' => 'product', 'variantable_id' => 1, 'sku' => 'A', 'position' => 0]);
        $price = Price::factory()->createQuietly(['priceable_type' => 'variant', 'priceable_id' => $variantId]);
        $this->lineFor($table, $morph, $variantId);

        try {
            $this->migration()->up();
            $this->fail("The migration ran while a {$table} row pointed at a variant.");
        } catch (VariantStillReferencedException $e) {
            $this->assertStringContainsString("1 in {$table}", $e->getMessage());
        }

        $this->assertTrue(Schema::hasTable('variants'));
        $this->assertTrue(Schema::hasTable('option_value_variant'));
        $this->assertModelExists($price);
    }

    /** @return array<string, array{string, string}> */
    public static function linesThatPointAtAVariant(): array
    {
        return [
            'a cart line' => ['cart_items', 'cartable'],
            'an order line' => ['order_lines', 'orderable'],
            'a purchase-order line' => ['purchase_order_lines', 'purchasable'],
        ];
    }

    #[Test]
    public function translations_and_category_and_tag_links_a_variant_owned_go_too(): void
    {
        $this->createShippedVariantTables();
        $variantId = DB::table('variants')->insertGetId(['variantable_type' => 'product', 'variantable_id' => 1, 'sku' => 'A', 'position' => 0]);
        $category = Category::factory()->create();
        $tag = Tag::factory()->create();
        DB::table('categorizables')->insert(['category_id' => $category->id, 'categorizable_type' => 'variant', 'categorizable_id' => $variantId]);
        DB::table('taggables')->insert(['tag_id' => $tag->id, 'taggable_type' => 'variant', 'taggable_id' => $variantId]);
        DB::table('translations')->insert(['translatable_type' => 'variant', 'translatable_id' => $variantId, 'locale' => 'en', 'field' => 'name', 'value' => 'Silver']);

        $this->migration()->up();

        $this->assertSame(0, DB::table('categorizables')->where('categorizable_type', 'variant')->count());
        $this->assertSame(0, DB::table('taggables')->where('taggable_type', 'variant')->count());
        $this->assertSame(0, DB::table('translations')->where('translatable_type', 'variant')->count());
        $this->assertModelExists($category);
        $this->assertModelExists($tag);
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

    private function lineFor(string $table, string $morph, int $variantId): void
    {
        match ($table) {
            'cart_items' => CartItem::factory()->create(['cartable_type' => 'variant', 'cartable_id' => $variantId]),
            'order_lines' => OrderLine::factory()->for(Order::factory())->create(['orderable_type' => 'variant', 'orderable_id' => $variantId]),
            'purchase_order_lines' => PurchaseOrderLine::factory()->create(['purchasable_type' => 'variant', 'purchasable_id' => $variantId]),
        };
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
