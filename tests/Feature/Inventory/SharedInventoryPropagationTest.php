<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Inventory;

use InOtherShops\Inventory\Actions\AdjustStock;
use InOtherShops\Inventory\Enums\StockMovementReason;
use InOtherShops\Tests\Stubs\TestStockableLocalizable;
use InOtherShops\Tests\TestCase;
use InOtherShops\Translation\Models\LocaleGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class SharedInventoryPropagationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function adjustment_does_not_propagate_when_group_does_not_share_inventory(): void
    {
        $group = LocaleGroup::factory()->create(['shares_inventory' => false]);
        $en = TestStockableLocalizable::factory()->create(['locale' => 'en', 'locale_group_id' => $group->id]);
        $de = TestStockableLocalizable::factory()->create(['locale' => 'de', 'locale_group_id' => $group->id]);

        app(AdjustStock::class)($en, 10, StockMovementReason::Received);

        $this->assertSame(10, $en->stockLevel());
        $this->assertSame(0, $de->stockLevel());
    }

    #[Test]
    public function adjustment_propagates_to_siblings_when_group_shares_inventory(): void
    {
        $group = LocaleGroup::factory()->sharingInventory()->create();
        $en = TestStockableLocalizable::factory()->create(['locale' => 'en', 'locale_group_id' => $group->id]);
        $de = TestStockableLocalizable::factory()->create(['locale' => 'de', 'locale_group_id' => $group->id]);

        app(AdjustStock::class)($en, 10, StockMovementReason::Received);

        $this->assertSame(10, $en->stockLevel());
        $this->assertSame(10, $de->stockLevel());
    }

    #[Test]
    public function decrement_propagates_to_siblings_when_group_shares_inventory(): void
    {
        $group = LocaleGroup::factory()->sharingInventory()->create();
        $en = TestStockableLocalizable::factory()->create(['locale' => 'en', 'locale_group_id' => $group->id]);
        $de = TestStockableLocalizable::factory()->create(['locale' => 'de', 'locale_group_id' => $group->id]);

        app(AdjustStock::class)($en, 50, StockMovementReason::Received);
        app(AdjustStock::class)($de, -3, StockMovementReason::Sold);

        $this->assertSame(47, $en->stockLevel());
        $this->assertSame(47, $de->stockLevel());
    }

    #[Test]
    public function each_member_gets_its_own_movement_ledger_entry(): void
    {
        $group = LocaleGroup::factory()->sharingInventory()->create();
        $en = TestStockableLocalizable::factory()->create(['locale' => 'en', 'locale_group_id' => $group->id]);
        $de = TestStockableLocalizable::factory()->create(['locale' => 'de', 'locale_group_id' => $group->id]);

        app(AdjustStock::class)($en, 10, StockMovementReason::Received);

        $this->assertSame(1, $en->stockItem->movements()->count());
        $this->assertSame(1, $de->stockItem->movements()->count());
    }

    #[Test]
    public function monolingual_stockable_propagation_is_a_noop(): void
    {
        $solo = TestStockableLocalizable::factory()->create(['locale' => 'en']);

        app(AdjustStock::class)($solo, 5, StockMovementReason::Received);

        $this->assertSame(5, $solo->stockLevel());
    }

    #[Test]
    public function returned_movement_is_the_primary_targets_movement(): void
    {
        $group = LocaleGroup::factory()->sharingInventory()->create();
        $en = TestStockableLocalizable::factory()->create(['locale' => 'en', 'locale_group_id' => $group->id]);
        TestStockableLocalizable::factory()->create(['locale' => 'de', 'locale_group_id' => $group->id]);

        $movement = app(AdjustStock::class)($en, 7, StockMovementReason::Received);

        $this->assertSame($en->stockItem->id, $movement->stock_item_id);
    }

    #[Test]
    public function primary_movement_tracks_the_entry_member_even_when_it_is_not_locked_first(): void
    {
        // G7: targets are now locked in a deterministic global order (by key), not
        // entry-member-first. `en` has the lower key, so adjusting through `de`
        // locks `en` first — the returned movement must still be `de`'s (primary
        // detection is by identity, not lock position).
        $group = LocaleGroup::factory()->sharingInventory()->create();
        $en = TestStockableLocalizable::factory()->create(['locale' => 'en', 'locale_group_id' => $group->id]);
        $de = TestStockableLocalizable::factory()->create(['locale' => 'de', 'locale_group_id' => $group->id]);

        $this->assertTrue($en->getKey() < $de->getKey(), 'fixture assumption: en is created before de');

        $movement = app(AdjustStock::class)($de, 7, StockMovementReason::Received);

        $this->assertSame($de->stockItem->id, $movement->stock_item_id);
    }

    #[Test]
    public function every_members_stock_level_reconciles_to_its_movement_ledger_regardless_of_entry_member(): void
    {
        // Adjust through different members of the group; each member's own
        // stock_level must equal the sum of its own movement ledger (G7 / T6).
        $group = LocaleGroup::factory()->sharingInventory()->create();
        $en = TestStockableLocalizable::factory()->create(['locale' => 'en', 'locale_group_id' => $group->id]);
        $de = TestStockableLocalizable::factory()->create(['locale' => 'de', 'locale_group_id' => $group->id]);
        $fr = TestStockableLocalizable::factory()->create(['locale' => 'fr', 'locale_group_id' => $group->id]);

        app(AdjustStock::class)($de, 20, StockMovementReason::Received);
        app(AdjustStock::class)($fr, -5, StockMovementReason::Sold);
        app(AdjustStock::class)($en, -3, StockMovementReason::Sold);

        foreach ([$en, $de, $fr] as $member) {
            $member->refresh();
            $this->assertSame(12, $member->stockLevel());
            $this->assertSame(
                12,
                (int) $member->stockItem->movements()->sum('quantity'),
                "member {$member->locale} stock_level must equal sum(movements)",
            );
        }
    }
}
