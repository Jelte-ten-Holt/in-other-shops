<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Payment\Stripe;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Commerce\Order\Models\Order;
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
use PHPUnit\Framework\Attributes\Test;
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

    #[Test]
    public function an_event_the_driver_does_not_handle_is_answered_without_a_ledger_row(): void
    {
        // Stripe sends a refund's update twice, as charge.refund.updated and as
        // refund.updated. The second is the captured body under its other name.
        $payment = $this->orderPayment();
        $event = json_decode($this->stripeFixture('charge.refund.updated.2026-03-25.dahlia.json'), true);
        $event['type'] = 'refund.updated';

        $returned = ($this->process)('stripe', $this->signedRequest(json_encode($event, JSON_THROW_ON_ERROR), time()));

        $this->assertNull($returned);
        $this->assertSame(0, WebhookEvent::query()->count());
        $this->assertSame(PaymentStatus::Succeeded, $payment->refresh()->status);
        $this->assertSame(0, $payment->amount_refunded);
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
