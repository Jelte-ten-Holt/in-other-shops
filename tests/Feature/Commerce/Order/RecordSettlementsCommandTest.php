<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Order;

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Commerce\Order\Models\Refund;
use InOtherShops\Payment\DTOs\Settlement;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Payment\PaymentGatewayManager;
use InOtherShops\Payment\Testing\FakePaymentGateway;
use InOtherShops\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * The scheduled settlement read (bookkeeping brief §12 P-2): which rows it
 * asks about, that it asks outside any lock, that it writes once, and that one
 * gateway error does not stop the run.
 */
final class RecordSettlementsCommandTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakePaymentGateway('fake');
        $this->app->make(PaymentGatewayManager::class)->extend('fake', fn (): FakePaymentGateway => $this->gateway);
    }

    #[Test]
    public function it_writes_the_settlement_and_paid_at_onto_a_settled_payment(): void
    {
        $payment = $this->payment('pi_usd');
        $this->gateway->settle($payment, new Settlement(
            reference: 'txn_usd',
            settledAt: CarbonImmutable::parse('2026-11-20 18:42:07'),
            amount: 5888,
            currency: 'EUR',
            fee: 334,
            net: 5554,
            exchangeRate: '0.9200000000',
            chargeReference: 'ch_usd',
        ));

        $this->artisan('commerce:record-settlements')->assertExitCode(0);

        $payment->refresh();
        $this->assertSame('ch_usd', $payment->charge_reference);
        $this->assertSame('txn_usd', $payment->settlement_reference);
        $this->assertSame('2026-11-20 18:42:07', $payment->paid_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(334, $payment->fee);
        $this->assertSame(5554, $payment->net);
        $this->assertSame(5888, $payment->settled_amount);
        $this->assertSame('EUR', $payment->settled_currency);
        $this->assertSame('0.9200000000', $payment->exchange_rate);
    }

    #[Test]
    public function a_payment_the_gateway_has_not_settled_yet_keeps_no_paid_at_and_is_asked_again_next_run(): void
    {
        $payment = $this->payment('pi_pending_capture');

        $this->artisan('commerce:record-settlements')->assertExitCode(0);
        $this->assertNull($payment->refresh()->paid_at);
        $this->assertNull($payment->settlement_reference);

        $this->gateway->settle($payment, $this->settlement('txn_late'));
        $this->artisan('commerce:record-settlements')->assertExitCode(0);

        $this->assertSame('txn_late', $payment->refresh()->settlement_reference);
        $this->assertNotNull($payment->paid_at);
        $this->assertCount(2, $this->gateway->settlementLookups());
    }

    #[Test]
    public function a_second_run_asks_nothing_about_a_settled_payment_and_changes_nothing(): void
    {
        $payment = $this->payment('pi_once');
        $this->gateway->settle($payment, $this->settlement('txn_first'));
        $this->artisan('commerce:record-settlements')->assertExitCode(0);
        $before = $payment->refresh()->getAttributes();

        // Even if the gateway now said something else, the row is not asked about.
        $this->gateway->settle($payment, $this->settlement('txn_second'));
        $this->artisan('commerce:record-settlements')->assertExitCode(0);

        $this->assertSame($before, $payment->refresh()->getAttributes());
        $this->assertCount(1, $this->gateway->settlementLookups());
    }

    #[Test]
    public function it_asks_only_about_succeeded_or_refunded_payments_from_the_last_thirty_days(): void
    {
        $asked = [
            $this->payment('pi_succeeded'),
            $this->payment('pi_partial', fn ($f) => $f->partiallyRefunded(300)),
            $this->payment('pi_refunded', fn ($f) => $f->refunded()),
        ];
        $this->payment('pi_pending', fn ($f) => $f->state(['status' => 'pending']));
        $this->payment('pi_failed', fn ($f) => $f->failed());
        $this->payment('pi_old', fn ($f) => $f->state(['created_at' => now()->subDays(31)]));

        foreach (['pi_succeeded', 'pi_partial', 'pi_refunded', 'pi_pending', 'pi_failed', 'pi_old'] as $reference) {
            $this->gateway->settle(Payment::query()->where('gateway_reference', $reference)->sole(), $this->settlement('txn_'.$reference));
        }

        $this->artisan('commerce:record-settlements')->assertExitCode(0);

        $this->assertEqualsCanonicalizing(
            array_map(fn (Payment $payment): int => $payment->getKey(), $asked),
            Payment::query()->whereNotNull('settlement_reference')->pluck('id')->all(),
        );
        $this->assertCount(3, $this->gateway->settlementLookups());
    }

    #[Test]
    public function a_payment_on_a_gateway_without_settlements_is_never_asked_about(): void
    {
        // `manual` is not even a registered gateway: a payment marked paid by hand.
        $manual = Payment::factory()->succeeded()->for(Order::factory(), 'payable')->create(['gateway' => 'manual']);

        $this->artisan('commerce:record-settlements')->assertExitCode(0);

        $this->assertNull($manual->refresh()->settlement_reference);
        $this->assertSame([], $this->gateway->settlementLookups());
    }

    #[Test]
    public function it_writes_a_refunds_settlement(): void
    {
        $payment = $this->payment('pi_refunded_usd', fn ($f) => $f->partiallyRefunded(1500));
        $this->gateway->settle($payment, $this->settlement('txn_charge'));
        $refund = Refund::factory()->create([
            'order_id' => $payment->payable_id,
            'payment_id' => $payment->getKey(),
            'gateway' => 'fake',
            'gateway_refund_id' => 're_usd',
            'amount' => 1500,
        ]);
        $this->gateway->settleRefund('re_usd', new Settlement(
            reference: 'txn_refund',
            settledAt: CarbonImmutable::parse('2026-12-10 09:30:00'),
            amount: 1425,
            currency: 'EUR',
            fee: 0,
            net: 1425,
            exchangeRate: '0.9500000000',
        ));

        $this->artisan('commerce:record-settlements')->assertExitCode(0);

        $refund->refresh();
        $this->assertSame('txn_refund', $refund->settlement_reference);
        $this->assertSame(0, $refund->fee);
        $this->assertSame(1425, $refund->settled_amount);
        $this->assertSame('EUR', $refund->settled_currency);
        $this->assertSame('0.9500000000', $refund->exchange_rate);
    }

    #[Test]
    public function it_asks_only_about_unsettled_refunds_from_the_last_thirty_days_that_the_gateway_knows(): void
    {
        $order = Order::factory()->create();
        $refund = fn (array $attributes): Refund => Refund::factory()->create(['order_id' => $order->getKey(), 'gateway' => 'fake', ...$attributes]);

        $asked = $refund(['gateway_refund_id' => 're_open']);
        $refund(['gateway_refund_id' => 're_settled', 'settlement_reference' => 'txn_already']);
        $refund(['gateway_refund_id' => 're_old', 'created_at' => now()->subDays(31)]);
        $refund(['gateway_refund_id' => null]);

        $this->artisan('commerce:record-settlements')->assertExitCode(0);

        $this->assertCount(1, $this->gateway->settlementLookups());
        $this->gateway->settleRefund('re_open', $this->settlement('txn_open'));
        $this->artisan('commerce:record-settlements')->assertExitCode(0);
        $this->assertSame('txn_open', $asked->refresh()->settlement_reference);
    }

    #[Test]
    public function every_gateway_call_runs_outside_any_transaction(): void
    {
        $payment = $this->payment('pi_lock');
        $this->gateway->settle($payment, $this->settlement('txn_lock'));
        Refund::factory()->create([
            'order_id' => $payment->payable_id,
            'payment_id' => $payment->getKey(),
            'gateway' => 'fake',
            'gateway_refund_id' => 're_lock',
        ]);

        // RefreshDatabase holds one transaction open around the test itself.
        $outside = $this->app['db']->transactionLevel();

        $this->artisan('commerce:record-settlements')->assertExitCode(0);

        $this->assertSame([$outside, $outside], $this->gateway->settlementLookups());
    }

    #[Test]
    public function a_gateway_error_on_one_row_is_reported_the_rest_are_still_recorded_and_it_exits_non_zero(): void
    {
        Exceptions::fake();
        $unreachable = $this->payment('pi_unreachable');
        $fine = $this->payment('pi_fine');
        $this->gateway->settle($unreachable, $this->settlement('txn_never'));
        $this->gateway->settle($fine, $this->settlement('txn_fine'));
        $this->gateway->failSettlementLookupFor('pi_unreachable');

        $this->artisan('commerce:record-settlements')
            ->expectsOutputToContain('Recorded 1 settlement(s); 1 gateway error(s).')
            ->assertExitCode(1);

        $this->assertNull($unreachable->refresh()->settlement_reference);
        $this->assertSame('txn_fine', $fine->refresh()->settlement_reference);
        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'fake: gateway unavailable');
    }

    #[Test]
    public function with_nothing_to_settle_it_exits_clean(): void
    {
        $this->artisan('commerce:record-settlements')
            ->expectsOutputToContain('Recorded 0 settlement(s); 0 gateway error(s).')
            ->assertExitCode(0);
    }

    #[Test]
    public function the_shipped_config_schedules_it_every_five_minutes(): void
    {
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'commerce:record-settlements'));

        $this->assertCount(1, $events);
        $this->assertSame('*/5 * * * *', $events->first()->expression);
        $this->assertTrue($events->first()->withoutOverlapping);
        $this->assertTrue($events->first()->runInBackground);
    }

    #[Test]
    #[DefineEnvironment('disableCommerceSchedule')]
    public function turning_the_commerce_schedule_off_takes_it_off_the_schedule(): void
    {
        $this->assertFalse(
            collect($this->app->make(Schedule::class)->events())
                ->contains(fn ($event): bool => str_contains((string) $event->command, 'commerce:record-settlements')),
        );
    }

    protected function disableCommerceSchedule($app): void
    {
        $app['config']->set('commerce.schedule.enabled', false);
    }

    private function payment(string $reference, ?callable $state = null): Payment
    {
        $factory = Payment::factory()->succeeded()->for(Order::factory(), 'payable');

        if ($state !== null) {
            $factory = $state($factory);
        }

        return $factory->create([
            'gateway' => 'fake',
            'gateway_reference' => $reference,
            'amount' => 6400,
        ]);
    }

    private function settlement(string $reference): Settlement
    {
        return new Settlement(
            reference: $reference,
            settledAt: CarbonImmutable::parse('2026-11-20 18:42:07'),
            amount: 6400,
            currency: 'EUR',
            fee: 120,
            net: 6280,
        );
    }
}
