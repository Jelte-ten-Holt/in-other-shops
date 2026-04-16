<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Inventory;

use InOtherShops\Inventory\Actions\AdjustStock;
use InOtherShops\Inventory\Actions\ReleaseExpiredReservations;
use InOtherShops\Inventory\Actions\ReleaseReservation;
use InOtherShops\Inventory\Actions\ReserveStock;
use InOtherShops\Inventory\Enums\ReservationStatus;
use InOtherShops\Inventory\Enums\StockMovementReason;
use InOtherShops\Inventory\Events\ReservationReleased;
use InOtherShops\Inventory\Events\StockReleased;
use InOtherShops\Inventory\Models\StockItem;
use InOtherShops\Inventory\Models\StockMovement;
use InOtherShops\Tests\Stubs\TestStockable;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

final class ReleaseExpiredReservationsTest extends TestCase
{
    use RefreshDatabase;

    private ReleaseExpiredReservations $action;

    private ReserveStock $reserve;

    protected function setUp(): void
    {
        parent::setUp();

        $adjust = new AdjustStock;
        $this->reserve = new ReserveStock($adjust);
        $this->action = new ReleaseExpiredReservations(new ReleaseReservation($adjust));
    }

    #[Test]
    public function it_releases_an_expired_reservation_and_restores_stock_level(): void
    {
        $stockable = $this->stockableWithLevel(10);

        ($this->reserve)(
            stockable: $stockable,
            quantity: 3,
            reservedUntil: now()->subMinute(),
        );

        $this->assertSame(7, $stockable->stockItem()->first()->stock_level);

        $released = ($this->action)();

        $this->assertCount(1, $released);
        $this->assertSame(ReservationStatus::Released, $released[0]->status);
        $this->assertNotNull($released[0]->release_movement_id);
        $this->assertSame(10, $stockable->stockItem()->first()->fresh()->stock_level);
    }

    #[Test]
    public function release_appends_a_new_movement_and_leaves_the_reserve_movement_untouched(): void
    {
        $stockable = $this->stockableWithLevel(10);

        $reservation = ($this->reserve)(
            stockable: $stockable,
            quantity: 3,
            reservedUntil: now()->subMinute(),
        );

        ($this->action)();

        $reserveMovement = StockMovement::query()->find($reservation->reserve_movement_id);
        $this->assertSame(-3, $reserveMovement->quantity);
        $this->assertSame(StockMovementReason::Reserved, $reserveMovement->reason);

        $releaseMovement = StockMovement::query()->find($reservation->fresh()->release_movement_id);
        $this->assertSame(3, $releaseMovement->quantity);
        $this->assertSame(StockMovementReason::Released, $releaseMovement->reason);

        $this->assertSame(2, StockMovement::query()->count());
    }

    #[Test]
    public function running_twice_in_succession_is_idempotent(): void
    {
        $stockable = $this->stockableWithLevel(10);

        ($this->reserve)(
            stockable: $stockable,
            quantity: 3,
            reservedUntil: now()->subMinute(),
        );

        $first = ($this->action)();
        $second = ($this->action)();

        $this->assertCount(1, $first);
        $this->assertCount(0, $second, 'Second run finds nothing — status is no longer Pending.');
        $this->assertSame(10, $stockable->stockItem()->first()->fresh()->stock_level,
            'Stock level must not be inflated by a double-release.');
    }

    #[Test]
    public function it_does_not_release_a_reservation_that_is_not_pending(): void
    {
        $stockable = $this->stockableWithLevel(10);

        $reservation = ($this->reserve)(
            stockable: $stockable,
            quantity: 3,
            reservedUntil: now()->subMinute(),
        );

        $reservation->update(['status' => ReservationStatus::Released, 'resolved_at' => now()]);

        $released = ($this->action)();

        $this->assertCount(0, $released);
        $this->assertSame(7, $stockable->stockItem()->first()->fresh()->stock_level,
            'Already-released reservation should not trigger another stock restoration.');
    }

    #[Test]
    public function it_leaves_non_expired_reservations_alone(): void
    {
        $stockable = $this->stockableWithLevel(10);

        ($this->reserve)(
            stockable: $stockable,
            quantity: 3,
            reservedUntil: now()->addMinutes(30),
        );

        $released = ($this->action)();

        $this->assertCount(0, $released);
        $this->assertSame(7, $stockable->stockItem()->first()->fresh()->stock_level);
    }

    #[Test]
    public function it_leaves_reservations_without_a_reserved_until_alone(): void
    {
        $stockable = $this->stockableWithLevel(10);

        ($this->reserve)(
            stockable: $stockable,
            quantity: 3,
            reservedUntil: null,
        );

        $released = ($this->action)();

        $this->assertCount(0, $released);
    }

    #[Test]
    public function it_dispatches_stock_released_and_reservation_released_once_per_release(): void
    {
        Event::fake([StockReleased::class, ReservationReleased::class]);

        $stockable = $this->stockableWithLevel(10);

        ($this->reserve)(
            stockable: $stockable,
            quantity: 3,
            reservedUntil: now()->subMinute(),
        );

        ($this->action)();
        ($this->action)();

        Event::assertDispatchedTimes(StockReleased::class, 1);
        Event::assertDispatchedTimes(ReservationReleased::class, 1);
    }

    private function stockableWithLevel(int $level): TestStockable
    {
        $stockable = TestStockable::factory()->create();

        StockItem::factory()
            ->for($stockable, 'stockable')
            ->create(['stock_level' => $level]);

        return $stockable;
    }
}
