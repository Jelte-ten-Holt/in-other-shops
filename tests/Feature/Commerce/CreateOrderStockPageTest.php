<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce;

use Filament\Forms\Components\Repeater;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use InOtherShops\Commerce\Filament\Resources\OrderResource\Pages\CreateOrder;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Commerce\Order\Models\OrderLine;
use InOtherShops\Inventory\Actions\AdjustHold;
use InOtherShops\Inventory\Actions\AdjustStock;
use InOtherShops\Inventory\Enums\StockMovementReason;
use InOtherShops\Inventory\Events\ReservationCreated;
use InOtherShops\Inventory\Models\StockHold;
use InOtherShops\Inventory\Models\StockItem;
use InOtherShops\Inventory\Models\StockReservation;
use InOtherShops\Tests\Stubs\TestStockableOrderable;
use InOtherShops\Tests\Support\BootsFilament;
use InOtherShops\Tests\TestCase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PDOException;
use PHPUnit\Framework\Attributes\Test;

/**
 * The admin order page takes stock (stock-holds brief §4.3), driven through
 * Livewire as an admin would.
 */
final class CreateOrderStockPageTest extends TestCase
{
    use BootsFilament;
    use RefreshDatabase;

    private \Closure $undoRepeaterFake;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn (): bool => true);
        $this->actingAs(new GenericUser(['id' => 1]));
        config(['currency.enabled' => ['EUR']]);
        $this->undoRepeaterFake = Repeater::fake();
    }

    protected function tearDown(): void
    {
        ($this->undoRepeaterFake)();

        parent::tearDown();
    }

    #[Test]
    public function an_order_takes_its_stock_from_main_stock_by_default(): void
    {
        $bowl = $this->product('Bowl', 5);

        $this->createPage([[$bowl, 2]])
            ->assertSee('2 from stock')
            ->call('create')
            ->assertHasNoFormErrors();

        $reservation = StockReservation::query()->sole();
        $this->assertSame(2, $reservation->quantity);
        $this->assertNull($reservation->reserved_until);
        $this->assertTrue($reservation->reference->is(Order::query()->sole()));
        $this->assertSame(3, $this->level($bowl));
    }

    #[Test]
    public function dont_take_stock_records_the_order_and_takes_nothing(): void
    {
        $bowl = $this->product('Bowl', 5);

        $this->createPage([[$bowl, 2]])
            ->fillForm(['_stock_mode' => 'none'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(0, StockReservation::query()->count());
        $this->assertSame(5, $this->level($bowl));
    }

    #[Test]
    public function an_order_from_a_hold_shows_each_lines_split_and_takes_it_so(): void
    {
        $bowl = $this->product('Bowl', 5);
        $hold = $this->hold('Markt', [[$bowl, 1]]);

        $this->createPage([[$bowl, 2]])
            ->fillForm(['_stock_mode' => 'hold'])
            ->assertFormSet(['_stock_hold_id' => $hold->id])
            ->assertSee('1 from Markt, 1 from stock')
            ->call('create')
            ->assertHasNoFormErrors();

        $rows = StockReservation::query()->where('reference_type', 'order')->orderBy('id')->get();
        $this->assertSame([1, 1], $rows->pluck('quantity')->all());
        $this->assertSame([$hold->id, null], $rows->pluck('stock_hold_id')->all());
        $this->assertSame(0, $hold->heldQuantity($bowl));
    }

    #[Test]
    public function take_from_a_hold_is_not_offered_when_no_open_hold_holds_a_line(): void
    {
        $bowl = $this->product('Bowl', 5);
        $this->hold('Markt', [[$this->product('Cup', 5), 1]]);

        $this->createPage([[$bowl, 1]])
            ->assertFormFieldExists('_stock_mode', fn ($field): bool => ! array_key_exists('hold', $field->getOptions()));
    }

    #[Test]
    public function a_line_main_stock_cannot_cover_names_the_holds_that_hold_it(): void
    {
        // D11: 0 in stock; 1 held in Markt — choose take from a hold.
        $bowl = $this->product('Bowl', 1);
        $this->hold('Markt', [[$bowl, 1]]);

        $page = $this->createPage([[$bowl, 1]])
            ->call('create')
            ->assertHasErrors(['data.lines.0.quantity']);

        $this->assertSame(['0 in stock; 1 held in Markt — choose “Take from a hold”.'], $page->errors()->get('data.lines.0.quantity'));
        $this->assertSame(0, Order::query()->count());
    }

    #[Test]
    public function the_hint_stays_away_while_main_stock_covers_the_line(): void
    {
        $bowl = $this->product('Bowl', 3);
        $this->hold('Markt', [[$bowl, 1]]);

        $this->createPage([[$bowl, 2]])
            ->call('create')
            ->assertHasNoErrors();

        $this->assertSame(1, Order::query()->count());
    }

    #[Test]
    public function a_backorderable_product_at_zero_reads_backordered_and_is_taken(): void
    {
        $madeToOrder = $this->product('Vase', 0, backorderable: true);

        $this->createPage([[$madeToOrder, 1]])
            ->assertSee('1 backordered')
            ->call('create')
            ->assertHasNoErrors();

        $this->assertSame(-1, $this->level($madeToOrder));
    }

    #[Test]
    public function a_lost_race_creates_nothing_and_says_so(): void
    {
        // The shop sells the last bowl between the page's check and the save.
        $bowl = $this->product('Bowl', 1);
        Order::created(fn () => app(AdjustStock::class)($bowl->fresh(), -1, StockMovementReason::Sold));

        $this->createPage([[$bowl, 1]])
            ->call('create')
            ->assertNotified(__('shops-commerce::orders.stock.notifications.refused'));

        $this->assertNothingCreated();
    }

    #[Test]
    public function a_deadlock_in_the_stock_step_creates_nothing_and_asks_to_try_again(): void
    {
        $bowl = $this->product('Bowl', 5);
        Event::listen(ReservationCreated::class, function (): void {
            $pdo = new PDOException('Deadlock found when trying to get lock');
            $pdo->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];
            (new \ReflectionProperty(\Exception::class, 'code'))->setValue($pdo, '40001');

            throw new QueryException('mysql', 'insert into stock_reservations', [], $pdo);
        });

        $this->createPage([[$bowl, 1]])
            ->call('create')
            ->assertNotified(__('shops-commerce::orders.stock.notifications.refused'));

        $this->assertNothingCreated();
        $this->assertSame(5, $this->level($bowl));
    }

    #[Test]
    public function a_hold_that_changed_since_its_split_was_shown_refuses_the_save_then_the_next_save_goes_through(): void
    {
        $bowl = $this->product('Bowl', 5);
        $hold = $this->hold('Markt', [[$bowl, 2]]);
        $page = $this->createPage([[$bowl, 2]])
            ->fillForm(['_stock_mode' => 'hold'])
            ->assertSee('2 from Markt');

        app(AdjustHold::class)($hold, $bowl, 1); // another admin's sale in between

        $page->call('create')->assertNotified(__('shops-commerce::orders.stock.notifications.refused'));
        $this->assertNothingCreated();

        $page->assertSee('1 from Markt, 1 from stock')
            ->call('create')
            ->assertHasNoErrors();

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(0, $hold->heldQuantity($bowl));
    }

    /**
     * @param  list<array{TestStockableOrderable, int}>  $lines
     */
    private function createPage(array $lines): Testable
    {
        return Livewire::test(CreateOrder::class)
            ->fillForm([
                'order_number' => 'ORD-'.fake()->unique()->numberBetween(1, 99999),
                'addresses' => [],
                'lines' => array_map(fn (array $line): array => [
                    'orderable_type' => $line[0]->getMorphClass(),
                    'orderable_id' => $line[0]->getKey(),
                    'description' => $line[0]->name,
                    'currency' => 'EUR',
                    'unit_price' => '15.00',
                    'quantity' => $line[1],
                    'line_total' => number_format(15 * $line[1], 2, '.', ''),
                ], $lines),
            ]);
    }

    private function product(string $name, int $level, bool $backorderable = false): TestStockableOrderable
    {
        $factory = TestStockableOrderable::factory();
        $product = ($backorderable ? $factory->backorderable() : $factory)->create(['name' => $name]);
        StockItem::factory()->for($product, 'stockable')->withLevel($level)->create();

        return $product;
    }

    /**
     * @param  list<array{TestStockableOrderable, int}>  $holding
     */
    private function hold(string $name, array $holding): StockHold
    {
        $hold = StockHold::factory()->create(['name' => $name]);

        foreach ($holding as [$product, $quantity]) {
            app(AdjustHold::class)($hold, $product, $quantity);
        }

        return $hold;
    }

    private function level(TestStockableOrderable $product): int
    {
        return (int) $product->stockItem()->first()->stock_level;
    }

    private function assertNothingCreated(): void
    {
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, OrderLine::query()->count());
        $this->assertSame(0, StockReservation::query()->where('reference_type', 'order')->count());
    }
}
