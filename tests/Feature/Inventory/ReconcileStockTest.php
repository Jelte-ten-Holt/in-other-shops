<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Console\Scheduling\Schedule;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InOtherShops\Inventory\Actions\AdjustHold;
use InOtherShops\Inventory\Actions\AdjustStock;
use InOtherShops\Inventory\Actions\ReserveStock;
use InOtherShops\Inventory\Actions\ReconcileStock;
use InOtherShops\Inventory\Enums\ReservationStatus;
use InOtherShops\Inventory\Enums\StockMovementReason;
use InOtherShops\Inventory\Events\InventoryDriftDetected;
use InOtherShops\Inventory\Models\StockHold;
use InOtherShops\Inventory\Models\StockReservation;
use InOtherShops\Tests\Stubs\TestStockable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The read-only inventory tripwire (F18 / G9 / T6). Each check must fire on the
 * exact drift it is meant to catch and stay silent when state is sound.
 */
final class ReconcileStockTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A reservation row carrying a valid reserve_movement_id (reusing an existing
     * ledger movement — its presence does not change sum(movements), so a sound
     * inventory stays sound).
     */
    private function reservationFor(TestStockable $widget, array $attributes = []): StockReservation
    {
        return StockReservation::factory()
            ->for($widget->stockItem)
            ->create(array_merge(
                ['reserve_movement_id' => $widget->stockItem->movements()->value('id')],
                $attributes,
            ));
    }

    #[Test]
    public function a_sound_inventory_reconciles_clean(): void
    {
        $widget = TestStockable::factory()->create();
        app(AdjustStock::class)($widget, 10, StockMovementReason::Received);
        app(AdjustStock::class)($widget, -3, StockMovementReason::Sold);

        $this->reservationFor($widget, ['reserved_until' => now()->addMinutes(30)]);

        $report = app(ReconcileStock::class)();

        $this->assertTrue($report->isClean());
        $this->assertSame(0, $report->issueCount());
    }

    #[Test]
    public function it_flags_a_stock_level_that_diverges_from_the_ledger(): void
    {
        $widget = TestStockable::factory()->create();
        app(AdjustStock::class)($widget, 10, StockMovementReason::Received);

        // Corrupt the aggregate directly, bypassing AdjustStock (and its cast) —
        // exactly the kind of out-of-band write the ledger is the truth against.
        DB::table('stock_items')->where('id', $widget->stockItem->id)->update(['stock_level' => 999]);

        $report = app(ReconcileStock::class)();

        $this->assertFalse($report->isClean());
        $this->assertCount(1, $report->levelMismatches);
        $this->assertSame(999, $report->levelMismatches[0]['recorded']);
        $this->assertSame(10, $report->levelMismatches[0]['ledger']);
        $this->assertSame($widget->stockItem->id, $report->levelMismatches[0]['stock_item_id']);
    }

    #[Test]
    public function it_flags_a_pending_reservation_with_no_ttl(): void
    {
        $widget = TestStockable::factory()->create();
        app(AdjustStock::class)($widget, 5, StockMovementReason::Received);

        $orphan = $this->reservationFor($widget, [
            'status' => ReservationStatus::Pending,
            'reserved_until' => null,
        ]);

        $report = app(ReconcileStock::class)();

        $this->assertFalse($report->isClean());
        $this->assertSame([$orphan->id], $report->nullTtlPendingReservationIds);
        $this->assertSame([], $report->overduePendingReservationIds);
    }

    #[Test]
    public function it_flags_a_pending_reservation_past_its_ttl(): void
    {
        $widget = TestStockable::factory()->create();
        app(AdjustStock::class)($widget, 5, StockMovementReason::Received);

        $overdue = $this->reservationFor($widget, [
            'status' => ReservationStatus::Pending,
            'reserved_until' => now()->subMinute(),
        ]);

        $report = app(ReconcileStock::class)();

        $this->assertFalse($report->isClean());
        $this->assertSame([$overdue->id], $report->overduePendingReservationIds);
        $this->assertSame([], $report->nullTtlPendingReservationIds);
    }

    #[Test]
    public function a_confirmed_reservation_without_a_ttl_is_not_flagged(): void
    {
        // Only PENDING rows are the cron's business; a Confirmed reservation with
        // a null TTL is normal (it was resolved, not orphaned).
        $widget = TestStockable::factory()->create();
        app(AdjustStock::class)($widget, 5, StockMovementReason::Received);

        $this->reservationFor($widget, [
            'status' => ReservationStatus::Confirmed,
            'reserved_until' => null,
        ]);

        $report = app(ReconcileStock::class)();

        $this->assertTrue($report->isClean());
    }

    #[Test]
    public function an_open_holds_reservations_are_not_errors(): void
    {
        $widget = TestStockable::factory()->create();
        app(AdjustStock::class)($widget, 5, StockMovementReason::Received);
        app(AdjustHold::class)(StockHold::factory()->create(), $widget, 2);

        $report = app(ReconcileStock::class)();

        $this->assertTrue($report->isClean());
        $this->assertSame([], $report->staleHoldIds);
    }

    #[Test]
    public function a_hold_open_past_its_day_is_a_warning_not_drift(): void
    {
        // D15: stale is a panel badge and a warning line — never a dirty
        // report, a failed nightly run or InventoryDriftDetected.
        Event::fake([InventoryDriftDetected::class]);
        $this->travelTo('2026-11-20 12:00:00');
        $widget = TestStockable::factory()->create();
        app(AdjustStock::class)($widget, 5, StockMovementReason::Received);
        $hold = StockHold::factory()->create(['name' => 'Markt 1 Nov', 'held_for' => '2026-11-01']);
        app(AdjustHold::class)($hold, $widget, 2);

        $report = app(ReconcileStock::class)();

        $this->assertSame([$hold->id], $report->staleHoldIds);
        $this->assertTrue($report->isClean());
        Event::assertNotDispatched(InventoryDriftDetected::class);
        $this->artisan('inventory:reconcile')
            ->expectsOutputToContain('Markt 1 Nov')
            ->assertExitCode(0);
    }

    #[Test]
    public function a_hold_is_aged_from_the_day_it_is_held_for_not_from_when_it_was_made(): void
    {
        // "Markt 15 Nov" made on 1 Nov is not flagged a week before the market.
        $this->travelTo('2026-11-01 09:00:00');
        $widget = TestStockable::factory()->create();
        app(AdjustStock::class)($widget, 5, StockMovementReason::Received);
        $market = StockHold::factory()->create(['held_for' => '2026-11-15']);
        $kept = StockHold::factory()->create(['held_for' => null]);
        app(AdjustHold::class)($market, $widget, 1);
        app(AdjustHold::class)($kept, $widget, 1);

        $this->travelTo('2026-11-12 09:00:00');
        $report = app(ReconcileStock::class)();

        $this->assertSame([$kept->id], $report->staleHoldIds, 'The hold with no day ages from its creation.');
    }

    #[Test]
    public function a_pending_reservation_on_a_released_hold_is_an_error(): void
    {
        $widget = TestStockable::factory()->create();
        app(AdjustStock::class)($widget, 5, StockMovementReason::Received);
        $hold = StockHold::factory()->released()->create();
        $stray = app(ReserveStock::class)($widget, 1, reference: $hold, expires: false);

        $report = app(ReconcileStock::class)();

        $this->assertFalse($report->isClean());
        $this->assertSame([$stray->id], $report->nullTtlPendingReservationIds);
        $this->assertSame([], $report->staleHoldIds);
    }

    #[Test]
    public function the_command_exits_non_zero_on_drift_and_zero_when_clean(): void
    {
        $widget = TestStockable::factory()->create();
        app(AdjustStock::class)($widget, 5, StockMovementReason::Received);

        $this->artisan('inventory:reconcile')->assertExitCode(0);

        $this->reservationFor($widget, ['reserved_until' => null]);

        $this->artisan('inventory:reconcile')->assertExitCode(1);
    }
    /**
     * S16 of the 2026-09-07 complexity audit: the tripwire was registered and
     * scheduled by nobody — neither consumer wired it — so detection latency
     * for stock drift was infinite. The package schedules its own tripwire now,
     * under the same `inventory.schedule.enabled` switch as the expiry sweep.
     */
    #[Test]
    public function the_package_schedules_the_tripwire_itself(): void
    {
        $commands = $this->scheduledCommands();

        $this->assertTrue(
            collect($commands)->contains(fn (string $c): bool => str_contains($c, 'inventory:reconcile')),
            "inventory:reconcile is not on the schedule. Scheduled: \n".implode("\n", $commands),
        );
    }

    /**
     * One switch, not a parallel one: a consumer that turns off the package's
     * inventory scheduling must not be left with a stray daily reconcile. The
     * gate is read in the provider's boot(), so it has to be set before boot.
     */
    #[Test]
    #[DefineEnvironment('disableInventorySchedule')]
    public function disabling_the_inventory_schedule_takes_the_tripwire_with_it(): void
    {
        $this->assertSame(
            [],
            array_values(array_filter(
                $this->scheduledCommands(),
                fn (string $c): bool => str_contains($c, 'inventory:'),
            )),
        );
    }

    protected function disableInventorySchedule($app): void
    {
        $app['config']->set('inventory.schedule.enabled', false);
    }

    /**
     * Scheduling only cuts detection latency if something downstream can react.
     * A `Log::warning` nobody reads is not detection — the event is.
     */
    #[Test]
    public function a_dirty_report_dispatches_the_drift_event(): void
    {
        $widget = TestStockable::factory()->create();
        app(AdjustStock::class)($widget, 5, StockMovementReason::Received);
        $this->reservationFor($widget, ['reserved_until' => null]);

        Event::fake([InventoryDriftDetected::class]);

        $report = app(ReconcileStock::class)();

        Event::assertDispatched(
            InventoryDriftDetected::class,
            fn (InventoryDriftDetected $event): bool => $event->report->issueCount() === $report->issueCount()
                && $event->report->nullTtlPendingReservationIds !== [],
        );
    }

    #[Test]
    public function a_clean_report_dispatches_nothing(): void
    {
        $widget = TestStockable::factory()->create();
        app(AdjustStock::class)($widget, 5, StockMovementReason::Received);
        $this->reservationFor($widget, ['reserved_until' => now()->addMinutes(30)]);

        Event::fake([InventoryDriftDetected::class]);

        app(ReconcileStock::class)();

        Event::assertNotDispatched(InventoryDriftDetected::class);
    }

    /** @return list<string> */
    private function scheduledCommands(): array
    {
        return array_map(
            fn ($event): string => (string) $event->command,
            $this->app->make(Schedule::class)->events(),
        );
    }
}
