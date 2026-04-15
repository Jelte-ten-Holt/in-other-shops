<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Inventory;

use InOtherShops\Inventory\Actions\AdjustStock;
use InOtherShops\Inventory\Actions\ReleaseExpiredReservations;
use InOtherShops\Inventory\Actions\ReserveStock;
use InOtherShops\Inventory\Enums\StockMovementReason;
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
        $this->action = new ReleaseExpiredReservations($adjust);
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
        $this->assertSame(10, $stockable->stockItem()->first()->fresh()->stock_level);
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

        $this->assertCount(1, $first, 'First run releases the expired reservation.');
        $this->assertCount(0, $second, 'Second run finds nothing to release — guard rejects already-released reason.');
        $this->assertSame(10, $stockable->stockItem()->first()->fresh()->stock_level,
            'Stock level must not be inflated by a double-release.');
    }

    #[Test]
    public function it_does_not_release_a_reservation_that_has_already_been_manually_released(): void
    {
        $stockable = $this->stockableWithLevel(10);

        ($this->reserve)(
            stockable: $stockable,
            quantity: 3,
            reservedUntil: now()->subMinute(),
        );

        // Simulate another worker having already flipped the movement.
        StockMovement::query()
            ->where('reason', StockMovementReason::Reserved)
            ->update(['reason' => StockMovementReason::Released, 'reserved_until' => null]);

        $released = ($this->action)();

        $this->assertCount(0, $released);
        $this->assertSame(7, $stockable->stockItem()->first()->fresh()->stock_level,
            'Manually "released" row should not be touched — the Adjusted stock_level stays as-is.');
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
    public function it_dispatches_stock_released_once_per_release(): void
    {
        Event::fake([StockReleased::class]);

        $stockable = $this->stockableWithLevel(10);

        ($this->reserve)(
            stockable: $stockable,
            quantity: 3,
            reservedUntil: now()->subMinute(),
        );

        ($this->action)();
        ($this->action)();

        Event::assertDispatchedTimes(StockReleased::class, 1);
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
