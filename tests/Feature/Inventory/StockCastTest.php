<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Inventory\DTOs\Stock;
use InOtherShops\Inventory\Exceptions\RawStockMutationException;
use InOtherShops\Inventory\Models\StockItem;
use InOtherShops\Tests\Stubs\TestStockable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Locks in the StockCast write-guard. The cast's job is to make
 * `$item->stock_level = 50; $item->save();` fail at the language level so
 * callers can't silently bypass AdjustStock's audit ledger and sibling
 * propagation. Reads stay int — that's the whole point of using
 * `CastsInboundAttributes` rather than a symmetric cast.
 */
final class StockCastTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_persists_a_stock_value_object_as_its_underlying_int(): void
    {
        $stockable = TestStockable::factory()->create();

        $stockItem = $stockable->stockItem()->create([
            'stock_level' => new Stock(42),
        ]);

        $this->assertSame(42, $stockItem->fresh()->stock_level,
            'Reads must return int — CastsInboundAttributes does not interpose on get.');
    }

    #[Test]
    public function direct_assignment_of_a_raw_int_throws_and_does_not_persist(): void
    {
        // The exact footgun motivating the cast: a caller types
        // `$item->stock_level = 50; $item->save();` and expects it to work.
        $stockItem = $this->existingStockItem(currentLevel: 10);

        try {
            $stockItem->stock_level = 50;
            $stockItem->save();
            $this->fail('Expected RawStockMutationException for raw-int assignment.');
        } catch (RawStockMutationException $e) {
            $this->assertStringContainsString('got int', $e->getMessage());
        }

        $this->assertSame(10, $stockItem->fresh()->stock_level,
            'A rejected write must not advance the stored stock level.');
    }

    #[Test]
    public function mass_assignment_via_update_with_a_raw_int_throws_and_does_not_persist(): void
    {
        // The other half of the same footgun: `$item->update(['stock_level' => 50])`.
        $stockItem = $this->existingStockItem(currentLevel: 10);

        try {
            $stockItem->update(['stock_level' => 50]);
            $this->fail('Expected RawStockMutationException for ->update() with raw int.');
        } catch (RawStockMutationException) {
            // expected
        }

        $this->assertSame(10, $stockItem->fresh()->stock_level);
    }

    #[Test]
    public function assigning_a_string_throws(): void
    {
        $stockItem = $this->existingStockItem(currentLevel: 10);

        try {
            $stockItem->stock_level = '50';
            $stockItem->save();
            $this->fail('Expected RawStockMutationException for string assignment.');
        } catch (RawStockMutationException $e) {
            $this->assertStringContainsString('got string', $e->getMessage());
        }

        $this->assertSame(10, $stockItem->fresh()->stock_level);
    }

    #[Test]
    public function assigning_null_throws(): void
    {
        // stock_level is non-nullable per migration; a caller passing null
        // is almost certainly a bug (forgotten value, broken form binding).
        $stockItem = $this->existingStockItem(currentLevel: 10);

        try {
            $stockItem->stock_level = null;
            $stockItem->save();
            $this->fail('Expected RawStockMutationException for null assignment.');
        } catch (RawStockMutationException $e) {
            $this->assertStringContainsString('got null', $e->getMessage());
        }

        $this->assertSame(10, $stockItem->fresh()->stock_level);
    }

    #[Test]
    public function reading_back_a_stock_assignment_yields_an_int_not_a_stock_object(): void
    {
        // Regression-pin for the CastsAttributes-vs-CastsInboundAttributes
        // decision. A symmetric cast would cache the assigned object and
        // return `Stock` on subsequent reads — breaking every reader in the
        // codebase. The inbound-only contract keeps the read shape int.
        $stockable = TestStockable::factory()->create();
        $stockItem = $stockable->stockItem()->create(['stock_level' => new Stock(5)]);

        $this->assertIsInt($stockItem->stock_level);
        $this->assertIsInt($stockItem->fresh()->stock_level);
    }

    private function existingStockItem(int $currentLevel): StockItem
    {
        $stockable = TestStockable::factory()->create();

        return StockItem::factory()
            ->for($stockable, 'stockable')
            ->withLevel($currentLevel)
            ->create();
    }
}
