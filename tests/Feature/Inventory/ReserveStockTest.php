<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Inventory;

use InOtherShops\Inventory\Actions\AdjustStock;
use InOtherShops\Inventory\Actions\ReserveStock;
use InOtherShops\Inventory\Enums\ReservationStatus;
use InOtherShops\Inventory\Enums\StockMovementReason;
use InOtherShops\Inventory\Events\ReservationCreated;
use InOtherShops\Inventory\Models\StockItem;
use InOtherShops\Inventory\Models\StockMovement;
use InOtherShops\Inventory\Models\StockReservation;
use InOtherShops\Tests\Stubs\TestStockable;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

final class ReserveStockTest extends TestCase
{
    use RefreshDatabase;

    private ReserveStock $reserve;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reserve = new ReserveStock(new AdjustStock);
    }

    #[Test]
    public function it_creates_a_pending_reservation_and_a_reserve_movement(): void
    {
        $stockable = $this->stockableWithLevel(10);

        $reservation = ($this->reserve)($stockable, quantity: 3);

        $this->assertInstanceOf(StockReservation::class, $reservation);
        $this->assertSame(3, $reservation->quantity);
        $this->assertSame(ReservationStatus::Pending, $reservation->status);
        $this->assertNull($reservation->resolved_at);
        $this->assertNull($reservation->release_movement_id);

        $movement = $reservation->reserveMovement;
        $this->assertSame(-3, $movement->quantity);
        $this->assertSame(StockMovementReason::Reserved, $movement->reason);
        $this->assertSame(7, $stockable->stockItem()->first()->stock_level);
    }

    #[Test]
    public function it_dispatches_reservation_created(): void
    {
        Event::fake([ReservationCreated::class]);

        $stockable = $this->stockableWithLevel(10);

        ($this->reserve)($stockable, quantity: 2);

        Event::assertDispatched(ReservationCreated::class, 1);
    }

    #[Test]
    public function quantity_is_always_stored_as_positive_even_when_passed_negative(): void
    {
        $stockable = $this->stockableWithLevel(10);

        $reservation = ($this->reserve)($stockable, quantity: -4);

        $this->assertSame(4, $reservation->quantity);
        $this->assertSame(-4, $reservation->reserveMovement->quantity);
        $this->assertSame(6, $stockable->stockItem()->first()->stock_level);
    }

    #[Test]
    public function the_ledger_movement_is_immutable_no_updated_at_column(): void
    {
        $stockable = $this->stockableWithLevel(10);

        ($this->reserve)($stockable, quantity: 1);

        /** @var StockMovement $movement */
        $movement = StockMovement::query()->first();

        $this->assertArrayNotHasKey('updated_at', $movement->getAttributes());
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
