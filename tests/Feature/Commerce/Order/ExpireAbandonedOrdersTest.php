<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Order;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Commerce\Order\Actions\ExpireAbandonedOrders;
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

/**
 * Order-expiry: the order-side half of closing F14. An unpaid Pending order past
 * its hold window is cancelled — its stock released and its gateway intent
 * cancelled — so a late payment can't land on it. A paid order, a fresh order,
 * or one whose intent is already live must NOT be expired.
 */
final class ExpireAbandonedOrdersTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $gateway;

    private ExpireAbandonedOrders $expire;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakePaymentGateway('fake');
        $this->app->make(PaymentGatewayManager::class)
            ->extend('fake', fn (): FakePaymentGateway => $this->gateway);

        $this->expire = $this->app->make(ExpireAbandonedOrders::class);
    }

    #[Test]
    public function it_cancels_an_unpaid_order_past_the_window_releasing_stock_and_the_intent(): void
    {
        $order = $this->pendingOrder(ageMinutes: 120);
        $reservation = $this->reservationFor($order, 2);
        $payment = $this->pendingPaymentFor($order, 'fake_pi_abandoned');

        $count = ($this->expire)();

        $this->assertSame(1, $count);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(ReservationStatus::Released, $reservation->fresh()->status,
            'Cancelling the order must release its held stock.');
        $this->assertSame(['fake_pi_abandoned'], $this->gateway->recordedCancellations(),
            'The gateway intent must be cancelled so a late payment cannot land.');
    }

    #[Test]
    public function it_voids_the_intent_of_an_order_abandoned_after_a_declined_attempt(): void
    {
        // A card decline moves the payment row to Failed but leaves the gateway
        // intent in requires_payment_method — live and retryable from the
        // shopper's still-open payment page. If expiry cancelled the order
        // without voiding that intent, a late retry would capture money against
        // a Cancelled order. The Failed payment must be swept like a Pending one.
        $order = $this->pendingOrder(ageMinutes: 120);
        $reservation = $this->reservationFor($order, 1);
        $payment = $this->pendingPaymentFor($order, 'fake_pi_declined');
        $payment->update(['status' => PaymentStatus::Failed]);

        $count = ($this->expire)();

        $this->assertSame(1, $count);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(ReservationStatus::Released, $reservation->fresh()->status);
        $this->assertSame(['fake_pi_declined'], $this->gateway->recordedCancellations(),
            'A declined attempt leaves a retryable intent — expiry must void it.');
    }

    #[Test]
    public function it_leaves_a_fresh_order_within_the_window_alone(): void
    {
        $order = $this->pendingOrder(ageMinutes: 5);
        $this->pendingPaymentFor($order, 'fake_pi_fresh');

        $count = ($this->expire)();

        $this->assertSame(0, $count);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame([], $this->gateway->recordedCancellations());
    }

    #[Test]
    public function it_never_expires_an_order_that_was_actually_paid(): void
    {
        $order = $this->pendingOrder(ageMinutes: 120);
        Payment::factory()->for($order, 'payable')->create([
            'gateway' => 'fake',
            'gateway_reference' => 'fake_pi_paid',
            'amount' => 1000,
            'currency' => Currency::EUR,
            'status' => PaymentStatus::Succeeded,
        ]);

        $count = ($this->expire)();

        $this->assertSame(0, $count);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame([], $this->gateway->recordedCancellations());
    }

    #[Test]
    public function it_aborts_when_the_gateway_intent_is_live(): void
    {
        // The knife-edge race: the intent succeeded/processing exactly as expiry
        // runs. cancelSession throws, so the order is NOT cancelled — the confirm
        // path will resolve it instead.
        $order = $this->pendingOrder(ageMinutes: 120);
        $reservation = $this->reservationFor($order, 1);
        $this->pendingPaymentFor($order, 'fake_pi_live');
        $this->gateway->markSessionLive('fake_pi_live');

        $count = ($this->expire)();

        $this->assertSame(0, $count);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame(ReservationStatus::Pending, $reservation->fresh()->status,
            'An order we could not cancel must keep its stock held.');
    }

    private function pendingOrder(int $ageMinutes): Order
    {
        return Order::factory()->create([
            'status' => OrderStatus::Pending,
            'created_at' => now()->subMinutes($ageMinutes),
        ]);
    }

    private function pendingPaymentFor(Order $order, string $reference): Payment
    {
        return Payment::factory()->for($order, 'payable')->create([
            'gateway' => 'fake',
            'gateway_reference' => $reference,
            'amount' => 1000,
            'currency' => Currency::EUR,
            'status' => PaymentStatus::Pending,
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
