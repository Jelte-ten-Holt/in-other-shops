<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Payment\Stripe;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Commerce\Order\Enums\RefundActorSource;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Commerce\Order\Models\Refund;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Payment\Actions\ProcessPaymentWebhook;
use InOtherShops\Payment\Drivers\Stripe\StripePaymentGateway;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Payment\Models\WebhookEvent;
use InOtherShops\Payment\PaymentGatewayManager;
use InOtherShops\Tests\Support\SignsStripeWebhooks;
use InOtherShops\Tests\TestCase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Stripe\Collection;
use Stripe\Service\RefundService;
use Stripe\StripeClient;

/**
 * The real Stripe driver behind ProcessPaymentWebhook, fed the event bodies
 * captured from a live delivery (tests/Fixtures/Stripe). Every other webhook
 * test goes through FakePaymentGateway, whose payloads are whatever the test
 * typed; this class is where the live shape meets the action.
 */
final class ProcessStripeWebhookTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;
    use SignsStripeWebhooks;

    /** The payment intent both captured events belong to. */
    private const string INTENT = 'pi_3UNEh8Qibh0bqUCQ0EvZ5Diw';

    /** The dashboard refund (EUR 22.00) the captured events are about. */
    private const string CAPTURED_REFUND = 're_3UNEh8Qibh0bqUCQ03L4oluj';

    /** The R4 run (2026-11-01): a payment with the refund-fail card, and its refund. */
    private const string FAIL_CARD_INTENT = 'pi_3UWvsPQibh0bqUCQ0z7WFEz3';

    private const string FAILED_REFUND = 're_3UWvsPQibh0bqUCQ0bLXCpoU';

    private RefundService $refunds;

    private ProcessPaymentWebhook $process;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refunds = Mockery::mock(RefundService::class);

        $client = Mockery::mock(StripeClient::class);
        $client->shouldReceive('getService')->with('refunds')->andReturn($this->refunds);

        $gateway = new StripePaymentGateway($client, self::WEBHOOK_SECRET);

        $this->app->make(PaymentGatewayManager::class)->extend('stripe', fn (): StripePaymentGateway => $gateway);
        $this->process = $this->app->make(ProcessPaymentWebhook::class);
    }

    /**
     * The gap this class exists for. The live charge.refunded body names no
     * refund: it carries the charge's cumulative and nothing else. The rows come
     * from the gateway's own list, which by the time the event is processed can
     * already hold a later refund whose event is still on its way.
     */
    #[Test]
    public function a_dashboard_refund_records_every_refund_the_gateway_lists_for_the_payment(): void
    {
        $payment = $this->orderPayment();

        // Newest first, as the API returns them.
        $this->refunds->shouldReceive('all')->once()
            ->with(['payment_intent' => self::INTENT, 'limit' => 100])
            ->andReturn(Collection::constructFrom([
                'object' => 'list',
                'has_more' => false,
                'data' => [
                    ['id' => 're_later', 'object' => 'refund', 'amount' => 1000, 'status' => 'succeeded'],
                    ['id' => self::CAPTURED_REFUND, 'object' => 'refund', 'amount' => 2200, 'status' => 'succeeded'],
                ],
            ]));

        $returned = ($this->process)('stripe', $this->signedRequest(
            $this->stripeFixture('charge.refunded.2026-03-25.dahlia.json'),
            time(),
        ));

        $this->assertTrue($payment->is($returned));
        $payment->refresh();
        $this->assertSame(2200, $payment->amount_refunded, 'the payment total follows the event, not the list');
        $this->assertSame(PaymentStatus::PartiallyRefunded, $payment->status);

        $refunds = $payment->payable->refunds()->orderBy('id')->get();
        $this->assertCount(2, $refunds);

        // Oldest first. VAT on 6350 charged {1900: 760, 700: 104}: 2200 refunded
        // reverses {263, 36}, 3200 reverses {383, 52}, so the later 1000 carries
        // the difference.
        $this->assertSame(self::CAPTURED_REFUND, $refunds[0]->gateway_refund_id);
        $this->assertSame(2200, $refunds[0]->amount);
        $this->assertSame([1900 => 263, 700 => 36], $this->taxByRate($refunds[0]));
        $this->assertSame(RefundActorSource::Gateway, $refunds[0]->actor_source);

        $this->assertSame('re_later', $refunds[1]->gateway_refund_id);
        $this->assertSame(1000, $refunds[1]->amount);
        $this->assertSame([1900 => 120, 700 => 16], $this->taxByRate($refunds[1]));
        $this->assertSame(RefundActorSource::Gateway, $refunds[1]->actor_source);
    }

    /**
     * Stripe sends a refund's update twice, as charge.refund.updated and as
     * refund.updated. Neither says anything the payment or its refund rows
     * follow, so both are answered as received: no ledger row, no row lock.
     * A forgotten null check here would answer 500 on every such delivery.
     */
    #[Test]
    #[DataProvider('refundUpdateEventTypes')]
    public function a_refund_update_event_is_answered_without_a_ledger_row(string $type): void
    {
        $payment = $this->orderPayment();
        $event = json_decode($this->stripeFixture('charge.refund.updated.2026-03-25.dahlia.json'), true);
        $event['type'] = $type;

        $returned = ($this->process)('stripe', $this->signedRequest(json_encode($event, JSON_THROW_ON_ERROR), time()));

        $this->assertNull($returned);
        $this->assertSame(0, WebhookEvent::query()->count());
        $this->assertSame(PaymentStatus::Succeeded, $payment->refresh()->status);
        $this->assertSame(0, $payment->amount_refunded);
    }

    /**
     * R4, on the bodies the 2026-11-01 sandbox run captured: a EUR 10.00 refund
     * created `succeeded`, then about two minutes later the same refund's
     * charge.refund.updated with `status` failed. Stripe put the money back and
     * lowered its own `amount_refunded` to 0 without a charge event, so this
     * event is the only signal.
     */
    #[Test]
    public function a_refund_that_fails_later_is_taken_back_out_of_the_payment_and_the_order(): void
    {
        $payment = $this->failCardPayment();
        $this->refunds->shouldReceive('all')->once()->andReturn($this->refundList('succeeded'));

        ($this->process)('stripe', $this->signedRequest($this->stripeFixture('charge.refunded.refund-fail-card.2026-03-25.dahlia.json'), time()));
        $this->assertSame(1000, $payment->refresh()->amount_refunded);
        $this->assertSame(1, $payment->payable->refunds()->count());

        $returned = ($this->process)('stripe', $this->signedRequest($this->stripeFixture('charge.refund.updated.failed.2026-03-25.dahlia.json'), time()));

        $this->assertTrue($payment->is($returned));
        $payment->refresh();
        $this->assertSame(0, $payment->amount_refunded);
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);

        $refund = $payment->payable->refunds()->sole();
        $this->assertSame(self::FAILED_REFUND, $refund->gateway_refund_id);
        $this->assertNotNull($refund->failed_at);
        $this->assertSame('expired_or_canceled_card', $refund->failure_reason);
        $this->assertSame(0, $payment->payable->refundedTotal());
    }

    /**
     * The other order of delivery: the failure first (no row yet, the total
     * stays at zero), then the charge.refunded sent before it. Its cumulative
     * still counts the failed refund; the list, which keeps only refunds that
     * returned money or are returning it, does not.
     */
    #[Test]
    public function a_refund_event_delivered_after_its_failure_moves_nothing(): void
    {
        $payment = $this->failCardPayment();

        ($this->process)('stripe', $this->signedRequest($this->stripeFixture('charge.refund.updated.failed.2026-03-25.dahlia.json'), time()));

        $this->refunds->shouldReceive('all')->once()->andReturn($this->refundList('failed'));
        ($this->process)('stripe', $this->signedRequest($this->stripeFixture('charge.refunded.refund-fail-card.2026-03-25.dahlia.json'), time()));

        $payment->refresh();
        $this->assertSame(0, $payment->amount_refunded);
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertSame(0, $payment->payable->refunds()->count());
    }

    /**
     * Stripe sends charge.refund.updated for any change to a refund, a failed
     * one included. Only the change INTO failed or canceled lowers the total; a
     * later update of a refund that had already given up must not lower it a
     * second time.
     *
     * @param  array<string, mixed>  $previous
     */
    #[Test]
    #[DataProvider('updatesOfAGivenUpRefund')]
    public function a_later_update_of_an_already_failed_refund_is_ignored(array $previous): void
    {
        $payment = $this->failCardPayment(refunded: 1000);
        $event = json_decode($this->stripeFixture('charge.refund.updated.failed.2026-03-25.dahlia.json'), true);
        $event['id'] = 'evt_later_update';
        $event['data']['previous_attributes'] = $previous;

        $returned = ($this->process)('stripe', $this->signedRequest(json_encode($event, JSON_THROW_ON_ERROR), time()));

        $this->assertNull($returned);
        $this->assertSame(0, WebhookEvent::query()->count());
        $this->assertSame(1000, $payment->refresh()->amount_refunded);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function updatesOfAGivenUpRefund(): array
    {
        return [
            'status unchanged' => [['destination_details' => ['card' => ['reference_status' => 'pending']]]],
            'canceled, then failed' => [['status' => 'canceled']],
        ];
    }

    /** @return array<string, array{string}> */
    public static function refundUpdateEventTypes(): array
    {
        return [
            'as captured' => ['charge.refund.updated'],
            'under its other name' => ['refund.updated'],
        ];
    }

    /** The refund the R4 run captured, as the gateway's list returns it. */
    private function refundList(string $status): Collection
    {
        return Collection::constructFrom([
            'object' => 'list',
            'has_more' => false,
            'data' => [['id' => self::FAILED_REFUND, 'object' => 'refund', 'amount' => 1000, 'status' => $status]],
        ]);
    }

    private function failCardPayment(int $refunded = 0): Payment
    {
        $order = Order::factory()->create([
            'total' => 1000,
            'tax' => 160,
            'tax_summary' => [['rate_bps' => 1900, 'taxable_base' => 840, 'tax' => 160]],
        ]);

        return Payment::factory()->for($order, 'payable')->create([
            'gateway' => 'stripe',
            'gateway_reference' => self::FAIL_CARD_INTENT,
            'amount' => 1000,
            'amount_refunded' => $refunded,
            'currency' => Currency::EUR,
            'status' => $refunded > 0 ? PaymentStatus::Refunded : PaymentStatus::Succeeded,
        ]);
    }

    /** @return array<int, int> rate in basis points => reversed tax in cents */
    private function taxByRate(Refund $refund): array
    {
        $tax = [];

        foreach ($refund->taxSummary() as $line) {
            $tax[$line->rateBps] = $line->tax;
        }

        return $tax;
    }

    private function orderPayment(): Payment
    {
        $order = Order::factory()->create([
            'total' => 6350,
            'tax' => 864,
            'tax_summary' => [
                ['rate_bps' => 1900, 'taxable_base' => 4000, 'tax' => 760],
                ['rate_bps' => 700, 'taxable_base' => 1486, 'tax' => 104],
            ],
        ]);

        return Payment::factory()->for($order, 'payable')->create([
            'gateway' => 'stripe',
            'gateway_reference' => self::INTENT,
            'amount' => 6350,
            'amount_refunded' => 0,
            'currency' => Currency::EUR,
            'status' => PaymentStatus::Succeeded,
        ]);
    }
}
