<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Order;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Commerce\Exceptions\CommerceException;
use InOtherShops\Commerce\Order\Actions\RefundOrder;
use InOtherShops\Commerce\Order\DTOs\RefundActor;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Inventory\Actions\AdjustStock;
use InOtherShops\Inventory\Actions\ReserveStock;
use InOtherShops\Inventory\Enums\ReservationStatus;
use InOtherShops\Inventory\Models\StockItem;
use InOtherShops\Inventory\Models\StockReservation;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Payment\PaymentGatewayManager;
use InOtherShops\Payment\Testing\FakePaymentGateway;
use InOtherShops\Tests\Stubs\TestStockable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class RefundOrderTest extends TestCase
{
    use RefreshDatabase;

    private RefundOrder $refundOrder;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(PaymentGatewayManager::class);
        $manager->extend('fake', fn (): FakePaymentGateway => new FakePaymentGateway('fake'));

        $this->refundOrder = $this->app->make(RefundOrder::class);
    }

    #[Test]
    public function a_partial_refund_records_a_refund_and_leaves_the_order_confirmed(): void
    {
        $order = $this->confirmedOrder();
        $this->paymentFor($order);

        $refund = ($this->refundOrder)(
            order: $order,
            actor: RefundActor::admin('7', 'Jelte'),
            amount: 1000,
            reason: 'Goodwill',
        );

        $this->assertSame(1000, $refund->amount);
        $this->assertSame('Goodwill', $refund->reason);
        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        $this->assertTrue($order->fresh()->isPartiallyRefunded());
    }

    #[Test]
    public function a_refund_with_restock_picks_releases_exactly_those_reservations(): void
    {
        $order = $this->confirmedOrder();
        $this->paymentFor($order);

        $keep = $this->reservationFor($order, 2);
        $restock = $this->reservationFor($order, 3);

        ($this->refundOrder)(
            order: $order,
            actor: RefundActor::admin('7'),
            amount: 500,
            restockReservationIds: [$restock->id],
        );

        $this->assertSame(ReservationStatus::Released, $restock->fresh()->status);
        $this->assertSame(ReservationStatus::Pending, $keep->fresh()->status, 'unpicked reservation untouched');
        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
    }

    #[Test]
    public function a_full_refund_with_cancel_cancels_the_order_and_blanket_releases_reservations(): void
    {
        $order = $this->confirmedOrder();
        $this->paymentFor($order);

        $a = $this->reservationFor($order, 2);
        $b = $this->reservationFor($order, 3);

        ($this->refundOrder)(
            order: $order,
            actor: RefundActor::admin('7'),
            cancelOrder: true,
        );

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(ReservationStatus::Released, $a->fresh()->status);
        $this->assertSame(ReservationStatus::Released, $b->fresh()->status);
        $this->assertTrue($order->fresh()->isRefunded());
    }

    #[Test]
    public function cancel_with_restock_picks_is_rejected(): void
    {
        $order = $this->confirmedOrder();
        $this->paymentFor($order);
        $reservation = $this->reservationFor($order, 2);

        $this->expectException(CommerceException::class);

        ($this->refundOrder)(
            order: $order,
            actor: RefundActor::admin('7'),
            cancelOrder: true,
            restockReservationIds: [$reservation->id],
        );
    }

    #[Test]
    public function a_restock_pick_that_cannot_be_released_is_surfaced(): void
    {
        $order = $this->confirmedOrder();
        $this->paymentFor($order);
        $foreign = $this->reservationFor($this->confirmedOrder(), 2); // belongs to a DIFFERENT order

        $this->expectException(CommerceException::class);

        ($this->refundOrder)(
            order: $order,
            actor: RefundActor::admin('7'),
            amount: 500,
            restockReservationIds: [$foreign->id],
        );
    }

    #[Test]
    public function an_order_with_no_refundable_payment_is_rejected(): void
    {
        $order = $this->confirmedOrder();

        $this->expectException(CommerceException::class);

        ($this->refundOrder)($order, RefundActor::admin('7'));
    }

    private function confirmedOrder(): Order
    {
        return Order::factory()->create([
            'status' => OrderStatus::Confirmed,
            'total' => 1760,
            'tax' => 210,
            'tax_summary' => [
                ['rate_bps' => 1900, 'taxable_base' => 843, 'tax' => 160],
                ['rate_bps' => 700, 'taxable_base' => 707, 'tax' => 50],
            ],
        ]);
    }

    private function paymentFor(Order $order): Payment
    {
        return Payment::factory()->for($order, 'payable')->create([
            'gateway' => 'fake',
            'gateway_reference' => 'fake_pi_'.uniqid(),
            'amount' => 1760,
            'amount_refunded' => 0,
            'currency' => Currency::EUR,
            'status' => PaymentStatus::Succeeded,
        ]);
    }

    private function reservationFor(Order $order, int $quantity): StockReservation
    {
        $stockable = TestStockable::factory()->create();
        StockItem::factory()->for($stockable, 'stockable')->withLevel(50)->create();

        return (new ReserveStock(new AdjustStock))(
            $stockable,
            quantity: $quantity,
            reference: $order,
            source: 'checkout',
        );
    }
}
