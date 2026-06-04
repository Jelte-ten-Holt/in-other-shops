<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InOtherShops\Inventory\Actions\AdjustStock;
use InOtherShops\Inventory\Actions\ReconcileStock;
use InOtherShops\Inventory\Enums\ReservationStatus;
use InOtherShops\Inventory\Enums\StockMovementReason;
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
    public function the_command_exits_non_zero_on_drift_and_zero_when_clean(): void
    {
        $widget = TestStockable::factory()->create();
        app(AdjustStock::class)($widget, 5, StockMovementReason::Received);

        $this->artisan('inventory:reconcile')->assertExitCode(0);

        $this->reservationFor($widget, ['reserved_until' => null]);

        $this->artisan('inventory:reconcile')->assertExitCode(1);
    }
}
