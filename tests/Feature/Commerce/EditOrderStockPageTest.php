<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce;

use Filament\Actions\Action;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use InOtherShops\Commerce\Filament\Resources\OrderResource\Pages\EditOrder;
use InOtherShops\Commerce\Filament\Resources\OrderResource\Pages\ListOrders;
use InOtherShops\Commerce\Order\Actions\ReserveOrderStock;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Commerce\Order\Models\OrderLine;
use InOtherShops\Inventory\Actions\AdjustHold;
use InOtherShops\Inventory\Actions\ReserveStock;
use InOtherShops\Inventory\Events\ReservationReleased;
use InOtherShops\Inventory\Models\StockHold;
use InOtherShops\Inventory\Models\StockItem;
use InOtherShops\Tests\Stubs\TestStockableOrderable;
use InOtherShops\Tests\Support\BootsFilament;
use InOtherShops\Tests\TestCase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use ReflectionObject;
use RuntimeException;

/**
 * The order edit page and the orders list once orders hold stock
 * (stock-holds brief §4.3, §4.4, §4.6).
 */
final class EditOrderStockPageTest extends TestCase
{
    use BootsFilament;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn (): bool => true);
        $this->actingAs(new GenericUser(['id' => 1]));
        config(['currency.enabled' => ['EUR']]);
    }

    #[Test]
    public function an_order_holding_stock_shows_what_it_holds_and_where_from(): void
    {
        [$order] = $this->orderFromHold(hold: 1, line: 2);

        Livewire::test(EditOrder::class, ['record' => $order->getKey()])
            ->assertSee('1 × Bowl (from Markt)')
            ->assertSee('1 × Bowl (from stock)');
    }

    #[Test]
    public function its_lines_are_locked_with_the_reason_and_its_notes_still_save(): void
    {
        // Filament skips a disabled relationship repeater on save, so the
        // model's line guard is never reached by a notes edit.
        [$order] = $this->orderFromHold(hold: 1, line: 1);

        Livewire::test(EditOrder::class, ['record' => $order->getKey()])
            ->assertFormFieldDisabled('lines')
            ->assertSee(__('shops-commerce::orders.stock.lines_locked'))
            ->fillForm(['notes' => 'Collected at the stall'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Collected at the stall', $order->fresh()->notes);
        $this->assertSame(1, $order->lines()->count());
    }

    #[Test]
    public function a_pending_checkout_orders_lines_are_locked_too(): void
    {
        $bowl = $this->product(5);
        $order = Order::factory()->create(['status' => OrderStatus::Pending]);
        OrderLine::factory()->for($order)->create(['orderable_type' => $bowl->getMorphClass(), 'orderable_id' => $bowl->id, 'quantity' => 1]);
        app(ReserveStock::class)($bowl, 1, reference: $order, source: 'checkout');

        Livewire::test(EditOrder::class, ['record' => $order->getKey()])
            ->assertFormFieldDisabled('lines');
    }

    #[Test]
    public function an_order_holding_nothing_keeps_its_lines_open(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::Pending]);

        Livewire::test(EditOrder::class, ['record' => $order->getKey()])
            ->assertFormFieldEnabled('lines')
            ->assertDontSee(__('shops-commerce::orders.stock.lines_locked'));
    }

    #[Test]
    public function mark_paid_says_held_stock_is_confirmed_on_an_order_holding_stock_and_not_adjusted_on_one_holding_none(): void
    {
        [$holding] = $this->orderFromHold(hold: 1, line: 1);
        $holdingNone = Order::factory()->create(['status' => OrderStatus::Pending, 'total' => 1500]);

        $this->assertSame(__('shops-commerce::orders.actions.mark_paid_modal_holding_stock'), $this->markPaidDescription($holding));
        $this->assertSame(__('shops-commerce::orders.actions.mark_paid_modal'), $this->markPaidDescription($holdingNone));
    }

    #[Test]
    public function deleting_an_unpaid_order_on_the_page_gives_its_stock_back(): void
    {
        [$order, $hold, $bowl] = $this->orderFromHold(hold: 1, line: 2);

        Livewire::test(EditOrder::class, ['record' => $order->getKey()])
            ->callAction('delete');

        $this->assertNull(Order::query()->find($order->getKey()));
        $this->assertSame(1, $hold->heldQuantity($bowl));
        $this->assertSame(4, (int) $bowl->stockItem()->first()->stock_level);
    }

    #[Test]
    public function a_release_that_fails_rolls_the_delete_back(): void
    {
        // The test panel has no panel-wide transactions, like IOW's: only the
        // action's own transaction keeps the row and its stock together.
        [$order] = $this->orderFromHold(hold: 0, line: 1);
        Event::listen(ReservationReleased::class, fn () => throw new RuntimeException('release failed'));

        try {
            Livewire::test(EditOrder::class, ['record' => $order->getKey()])->callAction('delete');
        } catch (RuntimeException) {
            // Filament surfaces it; what matters is what is left.
        }

        $this->assertNotNull(Order::query()->find($order->getKey()));
        $this->assertTrue($order->holdsStock());
    }

    #[Test]
    public function the_orders_list_badges_an_admin_order_holding_stock_past_the_threshold_only(): void
    {
        $this->travelTo('2026-11-01 10:00:00');
        [$stale] = $this->orderFromHold(hold: 0, line: 1);
        $this->travelTo('2026-11-08 09:00:00');
        [$fresh] = $this->orderFromHold(hold: 0, line: 1);
        $none = Order::factory()->create(['status' => OrderStatus::Pending]);
        $this->travelTo('2026-11-10 10:00:00');

        Livewire::test(ListOrders::class)
            ->assertTableColumnStateSet('stock_state', __('shops-commerce::orders.stock_state.stale', ['days' => 9]), record: $stale)
            ->assertTableColumnStateNotSet('stock_state', __('shops-commerce::orders.stock_state.stale', ['days' => 2]), record: $fresh)
            ->assertTableColumnStateSet('stock_state', null, record: $fresh)
            ->assertTableColumnStateSet('stock_state', null, record: $none);
    }

    /**
     * An admin order for `$line` bowls taking `$hold` of them from "Markt".
     *
     * @return array{Order, StockHold, TestStockableOrderable}
     */
    private function orderFromHold(int $hold, int $line): array
    {
        $bowl = TestStockableOrderable::query()->where('name', 'Bowl')->first() ?? $this->product(5);
        $markt = StockHold::factory()->create(['name' => 'Markt']);

        if ($hold > 0) {
            app(AdjustHold::class)($markt, $bowl, $hold);
        }

        $order = Order::factory()->create(['status' => OrderStatus::Pending, 'total' => 1500]);
        OrderLine::factory()->for($order)->create(['orderable_type' => $bowl->getMorphClass(), 'orderable_id' => $bowl->id, 'quantity' => $line]);
        app(ReserveOrderStock::class)($order, $hold > 0 ? $markt : null);

        return [$order, $markt, $bowl];
    }

    private function product(int $level): TestStockableOrderable
    {
        $product = TestStockableOrderable::factory()->create(['name' => 'Bowl']);
        StockItem::factory()->for($product, 'stockable')->withLevel($level)->create();

        return $product;
    }

    private function markPaidDescription(Order $order): ?string
    {
        $action = EditOrder::markPaidAction()->record($order);

        $resolve = (new ReflectionObject($action))->getMethod('getModalDescription');

        return (string) $resolve->invoke($action);
    }
}
