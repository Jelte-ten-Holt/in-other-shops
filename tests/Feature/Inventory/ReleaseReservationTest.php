<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Inventory\Actions\AdjustStock;
use InOtherShops\Inventory\Actions\ReleaseReservation;
use InOtherShops\Inventory\Actions\ReserveStock;
use InOtherShops\Inventory\Enums\ReservationStatus;
use InOtherShops\Inventory\Enums\StockMovementReason;
use InOtherShops\Inventory\Models\StockItem;
use InOtherShops\Inventory\Models\StockMovement;
use InOtherShops\Tests\Stubs\TestStockable;
use InOtherShops\Tests\Support\RecordsLockOrder;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Covers the F15 fix: ReleaseReservation accepts a release *reason* and writes
 * it onto the compensating `+X Released` ledger movement, so the audit trail
 * records why stock came back (payment failure, refund restock) rather than
 * echoing the original reservation text.
 */
final class ReleaseReservationTest extends TestCase
{
    use RecordsLockOrder;
    use RefreshDatabase;

    private ReserveStock $reserve;

    private ReleaseReservation $release;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reserve = new ReserveStock(new AdjustStock);
        $this->release = new ReleaseReservation(new AdjustStock);
    }

    #[Test]
    public function it_records_the_release_reason_on_the_ledger_movement(): void
    {
        $reservation = ($this->reserve)(
            $this->stockableWithLevel(10),
            quantity: 3,
            description: 'Checkout reservation for order ABC',
            reference: TestStockable::factory()->create(),
        );

        $released = ($this->release)($reservation, 'Payment failed for order ABC');

        $this->assertNotNull($released);
        $this->assertSame(ReservationStatus::Released, $released->status);
        $this->assertSame('Payment failed for order ABC', $this->latestReleaseMovement()->description);
    }

    #[Test]
    public function it_falls_back_to_the_reservation_description_when_no_reason_is_given(): void
    {
        $reservation = ($this->reserve)(
            $this->stockableWithLevel(10),
            quantity: 2,
            description: 'Checkout reservation for order XYZ',
            reference: TestStockable::factory()->create(),
        );

        ($this->release)($reservation);

        $this->assertSame('Checkout reservation for order XYZ', $this->latestReleaseMovement()->description);
    }

    #[Test]
    public function it_locks_the_stock_item_before_the_reservation_row(): void
    {
        // One lock order for every reservation actor (stock-holds brief §4.2):
        // a releaser that locked its row first could deadlock against a
        // checkout already holding the stock item and inserting into the same
        // index range. Sequential — this asserts the order, not contention.
        $reservation = ($this->reserve)($this->stockableWithLevel(10), quantity: 3);

        $this->recordLockOrder();
        ($this->release)($reservation);

        $this->assertLocksTakenInOrder(['stock_items', 'stock_reservations']);
    }

    #[Test]
    public function an_explicit_reference_rides_on_the_release_movement_when_the_reserved_for_row_is_gone(): void
    {
        // The order-deleted hook releases after the order row is gone, when
        // $reservation->reference resolves to null; the caller hands the
        // in-memory model so the movement still says which order it was.
        $order = TestStockable::factory()->create();
        $reservation = ($this->reserve)($this->stockableWithLevel(10), quantity: 3, reference: $order);
        $order->delete();

        ($this->release)($reservation->fresh(), reference: $order);

        $movement = $this->latestReleaseMovement();
        $this->assertSame($order->getMorphClass(), $movement->reference_type);
        $this->assertSame($order->getKey(), (int) $movement->reference_id);
    }

    #[Test]
    public function without_an_explicit_reference_the_release_movement_takes_the_reservations(): void
    {
        $order = TestStockable::factory()->create();
        $reservation = ($this->reserve)($this->stockableWithLevel(10), quantity: 3, reference: $order);

        ($this->release)($reservation);

        $movement = $this->latestReleaseMovement();
        $this->assertSame($order->getMorphClass(), $movement->reference_type);
        $this->assertSame($order->getKey(), (int) $movement->reference_id);
    }

    private function latestReleaseMovement(): StockMovement
    {
        return StockMovement::query()
            ->where('reason', StockMovementReason::Released)
            ->latest('id')
            ->firstOrFail();
    }

    private function stockableWithLevel(int $level): TestStockable
    {
        $stockable = TestStockable::factory()->create();

        StockItem::factory()
            ->for($stockable, 'stockable')
            ->withLevel($level)
            ->create();

        return $stockable;
    }
}
