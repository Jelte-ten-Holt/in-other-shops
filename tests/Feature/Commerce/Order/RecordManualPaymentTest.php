<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Order;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use InOtherShops\Commerce\Exceptions\CommerceException;
use InOtherShops\Commerce\Order\Actions\RecordManualPayment;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Events\PaymentSucceeded;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * "Mark paid" (bookkeeping brief §12 P-1): a manual payment for the full total
 * on the claimed day, through the same PaymentSucceeded a card payment fires.
 */
final class RecordManualPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // After every claimed day used below.
        $this->travelTo(CarbonImmutable::parse('2026-12-01 12:00:00', 'UTC'));
    }

    #[Test]
    public function it_records_a_succeeded_manual_payment_for_the_full_total_on_the_claimed_day(): void
    {
        Event::fake([PaymentSucceeded::class]);
        $order = Order::factory()->create(['total' => 9590, 'currency' => Currency::EUR]);
        $paidAt = CarbonImmutable::parse('2026-11-23 23:00:00', 'UTC');

        $payment = app(RecordManualPayment::class)($order, $paidAt);

        $stored = $payment->fresh();
        $this->assertSame('manual', $stored->gateway);
        $this->assertSame(PaymentStatus::Succeeded, $stored->status);
        $this->assertSame(9590, $stored->amount);
        $this->assertSame(Currency::EUR, $stored->currency);
        $this->assertNull($stored->gateway_reference);
        $this->assertSame('2026-11-23 23:00:00', $stored->paid_at->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($stored->settlement_reference);
        $this->assertTrue($stored->payable->is($order));
        $this->assertTrue($order->isFrozen());
        Event::assertDispatched(PaymentSucceeded::class, fn (PaymentSucceeded $event): bool => $event->payment->is($payment));
    }

    #[Test]
    public function a_day_that_has_not_come_yet_is_refused_without_a_payment_or_an_event(): void
    {
        Event::fake([PaymentSucceeded::class]);
        $order = Order::factory()->create(['total' => 9590]);

        $this->expectException(CommerceException::class);

        try {
            app(RecordManualPayment::class)($order, CarbonImmutable::now()->addDay()->startOfDay());
        } finally {
            $this->assertSame(0, Payment::query()->count());
            Event::assertNotDispatched(PaymentSucceeded::class);
        }
    }

    /** @return array<string, array{callable(Order): void}> */
    public static function ordersThatCannotBeMarkedPaid(): array
    {
        return [
            'cancelled' => [fn (Order $order) => $order->update(['status' => OrderStatus::Cancelled])],
            'nothing to pay' => [fn (Order $order) => $order->update(['total' => 0, 'subtotal' => 0])],
            'a pending card payment' => [fn (Order $order) => Payment::factory()->for($order, 'payable')->create(['amount' => 9590])],
            'already paid' => [fn (Order $order) => Payment::factory()->succeeded()->for($order, 'payable')->create(['amount' => 9590])],
        ];
    }

    #[Test]
    #[DataProvider('ordersThatCannotBeMarkedPaid')]
    public function an_order_that_does_not_accept_a_manual_payment_is_refused_without_a_new_payment_or_an_event(callable $state): void
    {
        $order = Order::factory()->create(['total' => 9590]);
        $state($order);
        $before = Payment::query()->count();
        Event::fake([PaymentSucceeded::class]);

        $this->assertFalse($order->fresh()->acceptsManualPayment());
        $this->expectException(CommerceException::class);

        try {
            app(RecordManualPayment::class)($order->fresh(), CarbonImmutable::parse('2026-11-23 23:00:00', 'UTC'));
        } finally {
            $this->assertSame($before, Payment::query()->count());
            Event::assertNotDispatched(PaymentSucceeded::class);
        }
    }

    #[Test]
    public function a_failed_card_attempt_does_not_stand_in_the_way(): void
    {
        Event::fake([PaymentSucceeded::class]);
        $order = Order::factory()->create(['total' => 9590]);
        Payment::factory()->failed()->for($order, 'payable')->create(['amount' => 9590]);

        app(RecordManualPayment::class)($order, CarbonImmutable::parse('2026-11-23 23:00:00', 'UTC'));

        $this->assertSame(1, $order->payments()->where('gateway', 'manual')->count());
    }

    #[Test]
    public function a_listener_that_fails_takes_the_payment_row_back_with_it(): void
    {
        Event::listen(PaymentSucceeded::class, function (): void {
            throw new RuntimeException('consumer listener failed');
        });
        $order = Order::factory()->create(['total' => 9590]);

        try {
            app(RecordManualPayment::class)($order, CarbonImmutable::parse('2026-11-23 23:00:00', 'UTC'));
            $this->fail('The listener failure was swallowed.');
        } catch (RuntimeException $e) {
            $this->assertSame('consumer listener failed', $e->getMessage());
        }

        $this->assertSame(0, Payment::query()->count());
        $this->assertFalse($order->isFrozen());
    }
}
