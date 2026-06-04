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
