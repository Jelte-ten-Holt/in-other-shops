<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Inventory;

use InOtherShops\Inventory\Actions\AdjustStock;
use InOtherShops\Inventory\Actions\ConfirmReservation;
use InOtherShops\Inventory\Actions\ReserveStock;
use InOtherShops\Inventory\Enums\ReservationStatus;
use InOtherShops\Inventory\Enums\StockMovementReason;
use InOtherShops\Inventory\Events\ReservationConfirmed;
use InOtherShops\Inventory\Models\StockItem;
use InOtherShops\Inventory\Models\StockMovement;
use InOtherShops\Tests\Stubs\TestStockable;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

final class ConfirmReservationTest extends TestCase
{
    use RefreshDatabase;

    private ReserveStock $reserve;

    private ConfirmReservation $confirm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reserve = new ReserveStock(new AdjustStock);
        $this->confirm = new ConfirmReservation;
    }

    #[Test]
    public function it_transitions_pending_reservations_to_confirmed_without_touching_the_ledger(): void
    {
        $stockable = $this->stockableWithLevel(10);
        $orderRef = TestStockable::factory()->create();

        $reservation = ($this->reserve)($stockable, quantity: 3, reference: $orderRef);

        $originalReserveMovementId = $reservation->reserve_movement_id;
        $originalReserveMovement = StockMovement::query()->find($originalReserveMovementId);
        $this->assertSame(-3, $originalReserveMovement->quantity);
        $this->assertSame(StockMovementReason::Reserved, $originalReserveMovement->reason);

        $confirmed = ($this->confirm)($orderRef);

        $this->assertCount(1, $confirmed);
        $this->assertSame(ReservationStatus::Confirmed, $confirmed[0]->status);
        $this->assertNotNull($confirmed[0]->resolved_at);

        // Ledger is untouched — original reserve movement is exactly as it was.
        $untouched = StockMovement::query()->find($originalReserveMovementId);
        $this->assertSame(-3, $untouched->quantity);
        $this->assertSame(StockMovementReason::Reserved, $untouched->reason);

        // No new movement was written on confirmation.
        $this->assertSame(1, StockMovement::query()->count());

        // Stock level unchanged — the reservation already decremented it.
        $this->assertSame(7, $stockable->stockItem()->first()->fresh()->stock_level);
    }

    #[Test]
    public function it_is_idempotent_a_second_confirm_finds_nothing_pending(): void
    {
        $stockable = $this->stockableWithLevel(10);
        $orderRef = TestStockable::factory()->create();

        ($this->reserve)($stockable, quantity: 2, reference: $orderRef);

        $first = ($this->confirm)($orderRef);
        $second = ($this->confirm)($orderRef);

        $this->assertCount(1, $first);
        $this->assertCount(0, $second, 'Second confirm finds no pending reservations — already confirmed.');
    }

    #[Test]
    public function it_confirms_only_reservations_matching_the_reference(): void
    {
        $stockable = $this->stockableWithLevel(20);
        $orderA = TestStockable::factory()->create();
        $orderB = TestStockable::factory()->create();

        ($this->reserve)($stockable, quantity: 2, reference: $orderA);
        ($this->reserve)($stockable, quantity: 5, reference: $orderB);

        $confirmed = ($this->confirm)($orderA);

        $this->assertCount(1, $confirmed);
        $this->assertSame(2, $confirmed[0]->quantity);
    }

    #[Test]
    public function it_ignores_already_resolved_reservations(): void
    {
        $stockable = $this->stockableWithLevel(10);
        $orderRef = TestStockable::factory()->create();

        $reservation = ($this->reserve)($stockable, quantity: 3, reference: $orderRef);
        $reservation->update(['status' => ReservationStatus::Released]);

        $confirmed = ($this->confirm)($orderRef);

        $this->assertCount(0, $confirmed);
    }

    #[Test]
    public function it_optionally_updates_description_on_confirm(): void
    {
        $stockable = $this->stockableWithLevel(10);
        $orderRef = TestStockable::factory()->create();

        ($this->reserve)($stockable, quantity: 1, reference: $orderRef, description: 'Held for cart #42');

        $confirmed = ($this->confirm)($orderRef, description: 'Order #42 confirmed');

        $this->assertSame('Order #42 confirmed', $confirmed[0]->description);
    }

    #[Test]
    public function it_dispatches_reservation_confirmed_once_per_confirmed_reservation(): void
    {
        Event::fake([ReservationConfirmed::class]);

        $stockable = $this->stockableWithLevel(10);
        $orderRef = TestStockable::factory()->create();

        ($this->reserve)($stockable, quantity: 1, reference: $orderRef);
        ($this->reserve)($stockable, quantity: 2, reference: $orderRef);

        ($this->confirm)($orderRef);
        ($this->confirm)($orderRef);

        Event::assertDispatchedTimes(ReservationConfirmed::class, 2);
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
