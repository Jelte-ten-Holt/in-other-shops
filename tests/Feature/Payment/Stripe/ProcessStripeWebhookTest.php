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

    /** @return array<string, array{string}> */
    public static function refundUpdateEventTypes(): array
    {
        return [
            'as captured' => ['charge.refund.updated'],
            'under its other name' => ['refund.updated'],
        ];
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
