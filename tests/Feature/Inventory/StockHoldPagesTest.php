<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Inventory;

use Filament\Forms\Components\Repeater;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Inventory\Actions\AdjustHold;
use InOtherShops\Inventory\Actions\AdjustStock;
use InOtherShops\Inventory\Actions\ReserveFromHold;
use InOtherShops\Inventory\Enums\StockMovementReason;
use InOtherShops\Inventory\Filament\Resources\StockHoldResource;
use InOtherShops\Inventory\Filament\Resources\StockHoldResource\Pages\CreateStockHold;
use InOtherShops\Inventory\Filament\Resources\StockHoldResource\Pages\EditStockHold;
use InOtherShops\Inventory\Filament\Resources\StockHoldResource\Pages\ListStockHolds;
use InOtherShops\Inventory\Models\StockHold;
use InOtherShops\Inventory\Models\StockItem;
use InOtherShops\Inventory\Models\StockReservation;
use InOtherShops\Tests\Stubs\Filament\StubEditableResource\Pages\EditStubEditable;
use InOtherShops\Tests\Stubs\TestEditable;
use InOtherShops\Tests\Stubs\TestStockable;
use InOtherShops\Tests\Stubs\TestStockableLocalizable;
use InOtherShops\Tests\Support\BootsFilament;
use InOtherShops\Tests\Support\RecordsLockOrder;
use InOtherShops\Tests\TestCase;
use InOtherShops\Translation\Models\LocaleGroup;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Stock holds page (stock-holds brief D7, §4.1), driven through Livewire.
 */
final class StockHoldPagesTest extends TestCase
{
    use BootsFilament;
    use RecordsLockOrder;
    use RefreshDatabase;

    private \Closure $undoRepeaterFake;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn (): bool => true);
        $this->actingAs(new GenericUser(['id' => 1]));
        $this->undoRepeaterFake = Repeater::fake();
    }

    protected function tearDown(): void
    {
        ($this->undoRepeaterFake)();

        parent::tearDown();
    }

    #[Test]
    public function creating_a_hold_takes_its_products_off_sale(): void
    {
        $bowl = $this->product('Bowl', 5);

        Livewire::test(CreateStockHold::class)
            ->fillForm([
                'name' => 'Markt 15 Nov',
                'held_for' => '2026-11-15',
                '_lines' => [['stockable' => StockHoldResource::stockableKey($bowl), 'quantity' => 2]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $hold = StockHold::query()->sole();
        $this->assertSame('2026-11-15', $hold->held_for->toDateString());
        $this->assertSame(2, $hold->heldQuantity($bowl));
        $this->assertSame(3, $this->level($bowl));
    }

    #[Test]
    public function a_hold_whose_second_product_is_refused_leaves_no_hold_and_no_reservation(): void
    {
        // The test panel has no panel-wide transactions, like IOW's: only the
        // page's own transaction undoes the first product.
        $bowl = $this->product('Bowl', 5);
        $cup = $this->product('Cup', 1);

        Livewire::test(CreateStockHold::class)
            ->fillForm([
                'name' => 'Markt',
                '_lines' => [
                    ['stockable' => StockHoldResource::stockableKey($bowl), 'quantity' => 1],
                    ['stockable' => StockHoldResource::stockableKey($cup), 'quantity' => 3],
                ],
            ])
            ->call('create')
            ->assertNotified(__('shops-inventory::holds.notifications.refused'));

        $this->assertSame(0, StockHold::query()->count());
        $this->assertSame(0, StockReservation::query()->count());
        $this->assertSame(5, $this->level($bowl));
    }

    #[Test]
    public function saving_two_products_locks_both_stock_items_before_any_row(): void
    {
        // Stock-holds brief §4.2: every stock item up front, sorted — not one
        // product at a time, which can deadlock against a checkout holding the
        // second product's item.
        $bowl = $this->product('Bowl', 5);
        $cup = $this->product('Cup', 5);
        $page = Livewire::test(CreateStockHold::class)->fillForm([
            'name' => 'Markt',
            '_lines' => [
                ['stockable' => StockHoldResource::stockableKey($cup), 'quantity' => 1],
                ['stockable' => StockHoldResource::stockableKey($bowl), 'quantity' => 1],
            ],
        ]);

        $this->recordLockOrder();
        $page->call('create')->assertHasNoFormErrors();

        $this->assertLocksTakenInOrder(['stock_holds', 'stock_items', 'stock_reservations']);
    }

    #[Test]
    public function editing_a_hold_sets_each_product_to_its_number_and_a_removed_one_to_zero(): void
    {
        $bowl = $this->product('Bowl', 5);
        $cup = $this->product('Cup', 5);
        $plate = $this->product('Plate', 5);
        $hold = $this->hold('Markt', [[$bowl, 2], [$plate, 1]]);

        Livewire::test(EditStockHold::class, ['record' => $hold->getKey()])
            ->fillForm(['_lines' => [
                ['stockable' => StockHoldResource::stockableKey($bowl), 'quantity' => 1],
                ['stockable' => StockHoldResource::stockableKey($cup), 'quantity' => 3],
            ]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(1, $hold->heldQuantity($bowl));
        $this->assertSame(3, $hold->heldQuantity($cup));
        $this->assertSame(0, $hold->heldQuantity($plate));
        $this->assertSame(5, $this->level($plate));
    }

    #[Test]
    public function a_hold_on_two_editions_of_one_stock_is_one_line_and_saving_it_unchanged_keeps_it(): void
    {
        // An EN hold of 2; a DE order takes 1 and is deleted, so the unit comes
        // back on the DE edition: the hold's rows now sit on both siblings.
        [$en, $de] = $this->sharedEditions(5);
        $hold = $this->holdOnBothEditions($en, $de);
        $this->assertSame(2, $hold->heldQuantity($en));
        $this->assertSame(2, $hold->pendingReservations()->count(), 'Fixture: one row on each edition.');

        Livewire::test(EditStockHold::class, ['record' => $hold->getKey()])
            ->assertFormSet(['_lines' => [['stockable' => StockHoldResource::stockableKey($en), 'quantity' => 2]]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(2, $hold->heldQuantity($en), 'Saving the form unchanged keeps what the group holds.');
        $this->assertSame(3, (int) $en->stockItem()->first()->stock_level, 'No unit went back on sale.');
    }

    #[Test]
    public function removing_a_shared_stocks_line_gives_back_the_whole_group(): void
    {
        [$en, $de] = $this->sharedEditions(5);
        $hold = $this->holdOnBothEditions($en, $de);
        $bowl = $this->product('Bowl', 5);
        app(AdjustHold::class)($hold, $bowl, 1);

        Livewire::test(EditStockHold::class, ['record' => $hold->getKey()])
            ->fillForm(['_lines' => [['stockable' => StockHoldResource::stockableKey($bowl), 'quantity' => 1]]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(0, $hold->heldQuantity($en));
        $this->assertSame(5, (int) $de->stockItem()->first()->stock_level);
    }

    #[Test]
    public function two_lines_naming_editions_of_one_stock_add_up(): void
    {
        [$en, $de] = $this->sharedEditions(5);

        Livewire::test(CreateStockHold::class)
            ->fillForm([
                'name' => 'Markt',
                '_lines' => [
                    ['stockable' => StockHoldResource::stockableKey($en), 'quantity' => 1],
                    ['stockable' => StockHoldResource::stockableKey($de), 'quantity' => 1],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(2, StockHold::query()->sole()->heldQuantity($de));
    }

    #[Test]
    public function units_that_came_back_to_the_hold_while_the_page_was_open_stay_held(): void
    {
        // The form never listed the cup; an order that took it from this hold
        // was deleted while the page was open. Saving must not put it on sale.
        $bowl = $this->product('Bowl', 5);
        $cup = $this->product('Cup', 5);
        $hold = $this->hold('Markt', [[$bowl, 1], [$cup, 1]]);
        $order = Order::factory()->create(['status' => OrderStatus::Pending]);
        app(ReserveFromHold::class)($hold, $cup, 1, $order, 'Moved');

        $page = Livewire::test(EditStockHold::class, ['record' => $hold->getKey()])
            ->assertFormSet(['_lines' => [['stockable' => StockHoldResource::stockableKey($bowl), 'quantity' => 1]]]);

        $order->delete(); // the cup goes back to the hold

        $page->call('save')->assertHasNoFormErrors();

        $this->assertSame(1, $hold->heldQuantity($cup));
        $this->assertSame(1, $hold->heldQuantity($bowl));
    }

    #[Test]
    public function a_released_hold_is_read_only(): void
    {
        $hold = StockHold::factory()->released()->create();

        Livewire::test(EditStockHold::class, ['record' => $hold->getKey()])
            ->assertFormFieldDisabled('name')
            ->assertActionDoesNotExist('save');
    }

    #[Test]
    public function release_on_the_list_gives_everything_back(): void
    {
        $bowl = $this->product('Bowl', 5);
        $hold = $this->hold('Markt', [[$bowl, 2]]);

        Livewire::test(ListStockHolds::class)
            ->callTableAction('release', $hold)
            ->assertNotified(__('shops-inventory::holds.notifications.released', ['name' => 'Markt']));

        $this->assertTrue($hold->fresh()->isReleased());
        $this->assertSame(5, $this->level($bowl));
    }

    #[Test]
    public function the_list_filters_by_product_and_badges_a_stale_hold(): void
    {
        $this->travelTo('2026-11-20 12:00:00');
        $bowl = $this->product('Bowl', 5);
        $cup = $this->product('Cup', 5);
        $market = $this->hold('Markt 1 Nov', [[$bowl, 1]], heldFor: '2026-11-01');
        $kept = $this->hold('Kept for Ana', [[$cup, 1]], heldFor: '2026-11-19');

        Livewire::test(ListStockHolds::class)
            ->assertCanSeeTableRecords([$market, $kept])
            ->assertTableColumnStateSet('state', __('shops-inventory::holds.state.stale'), record: $market)
            ->assertTableColumnStateSet('state', null, record: $kept)
            ->filterTable('product', StockHoldResource::stockableKey($cup))
            ->assertCanSeeTableRecords([$kept])
            ->assertCanNotSeeTableRecords([$market]);
    }

    #[Test]
    public function a_products_stock_section_says_what_is_held_and_where(): void
    {
        $vase = TestEditable::factory()->create();
        StockItem::factory()->for($vase, 'stockable')->withLevel(5)->create();
        $this->hold('Markt 15 Nov', [[$vase, 2]]);
        $this->hold('Kept for Ana', [[$vase, 1]]);

        Livewire::test(EditStubEditable::class, ['record' => $vase->getKey()])
            ->assertSee('Held')
            ->assertSeeHtml('Markt 15 Nov: 2')
            ->assertSeeHtml('Kept for Ana: 1');
    }

    /**
     * The reviewer's sequence: an EN hold of 2; a DE order takes 1 (the other
     * stays on EN); the order is deleted and its unit comes back on DE.
     */
    private function holdOnBothEditions(TestStockableLocalizable $en, TestStockableLocalizable $de): StockHold
    {
        $hold = $this->hold('Markt', [[$en, 2]]);
        $order = Order::factory()->create(['status' => OrderStatus::Pending]);
        app(ReserveFromHold::class)($hold, $de, 1, $order, 'Moved');
        $order->delete();

        return $hold;
    }

    /**
     * @return array{TestStockableLocalizable, TestStockableLocalizable}
     */
    private function sharedEditions(int $level): array
    {
        $group = LocaleGroup::factory()->sharingInventory()->create();
        $en = TestStockableLocalizable::factory()->create(['name' => 'Rulebook', 'locale' => 'en', 'locale_group_id' => $group->id]);
        $de = TestStockableLocalizable::factory()->create(['name' => 'Regelbuch', 'locale' => 'de', 'locale_group_id' => $group->id]);
        app(AdjustStock::class)($en, $level, StockMovementReason::Restock);

        return [$en, $de];
    }

    private function product(string $name, int $level): TestStockable
    {
        $product = TestStockable::factory()->create(['name' => $name]);
        StockItem::factory()->for($product, 'stockable')->withLevel($level)->create();

        return $product;
    }

    /**
     * @param  list<array{\Illuminate\Database\Eloquent\Model&\InOtherShops\Inventory\Contracts\HasStock, int}>  $holding
     */
    private function hold(string $name, array $holding, ?string $heldFor = null): StockHold
    {
        $hold = StockHold::factory()->create(['name' => $name, 'held_for' => $heldFor]);

        foreach ($holding as [$product, $quantity]) {
            app(AdjustHold::class)($hold, $product, $quantity);
        }

        return $hold;
    }

    private function level(TestStockable $product): int
    {
        return (int) $product->stockItem()->first()->stock_level;
    }
}
