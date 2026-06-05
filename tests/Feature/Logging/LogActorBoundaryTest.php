<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Logging;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InOtherShops\Commerce\Order\Events\RefundRecorded;
use InOtherShops\Commerce\Order\Models\Refund;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Logging\Enums\LogActorType;
use InOtherShops\Logging\Handlers\DatabaseLogHandler;
use InOtherShops\Logging\LogContext;
use InOtherShops\Payment\Actions\ProcessPaymentWebhook;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Payment\PaymentGatewayManager;
use InOtherShops\Payment\Testing\FakePaymentGateway;
use InOtherShops\Tests\Stubs\TestPayable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Each boundary that opens a unit of work must establish the audit actor so the
 * rows produced downstream aren't anonymous (F21, P2). This proves the four
 * package-side boundaries: payment webhook → gateway, scheduled command →
 * system, and the refund log path deriving its actor from the durable
 * RefundActor (gateway vs admin). The agent boundary is proved in
 * {@see \InOtherShops\Tests\Feature\Agent\AuthenticateAgentMiddlewareTest}.
 */
final class LogActorBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Route commerce to the DB handler so the real refund subscriber writes
        // a row we can assert the actor columns on (mirrors AuditPipelineRowTest).
        $app['config']->set('domain-log.channels.commerce', [
            ['handler' => DatabaseLogHandler::class, 'with' => []],
        ]);
    }

    #[Test]
    public function a_payment_webhook_establishes_the_gateway_as_the_boundary_actor(): void
    {
        $gateway = new FakePaymentGateway('fake');
        $this->app->make(PaymentGatewayManager::class)
            ->extend('fake', fn (): FakePaymentGateway => $gateway);

        $payment = $this->paymentWithReference('fake_pi_actor', PaymentStatus::Pending);
        $request = $gateway->simulateWebhook($payment, PaymentStatus::Succeeded, 'evt_actor');

        $this->app->make(ProcessPaymentWebhook::class)('fake', $request);

        $actor = $this->app->make(LogContext::class)->actor();
        $this->assertNotNull($actor);
        $this->assertSame(LogActorType::Gateway, $actor->type);
        $this->assertSame('fake', $actor->label);
    }

    #[Test]
    public function a_scheduled_command_establishes_itself_as_a_system_actor(): void
    {
        $this->artisan('inventory:release-expired')->assertSuccessful();

        $actor = $this->app->make(LogContext::class)->actor();
        $this->assertNotNull($actor);
        $this->assertSame(LogActorType::System, $actor->type);
        $this->assertSame('inventory:release-expired', $actor->label);
    }

    #[Test]
    public function a_gateway_refund_is_logged_as_a_gateway_actor_not_the_ambient_request(): void
    {
        $refund = Refund::factory()->byGateway('stripe')->create(['gateway' => 'stripe']);

        RefundRecorded::dispatch($refund);

        $row = DB::table('domain_logs')->where('channel', 'commerce')->latest('id')->first();
        $this->assertSame('gateway', $row->actor_type);
        $this->assertSame('stripe', $row->actor_label);
    }

    #[Test]
    public function an_admin_refund_is_logged_as_a_user_actor_carrying_the_admin_identity(): void
    {
        $refund = Refund::factory()->create([
            'actor_id' => '42',
            'actor_label' => 'Jelte',
        ]);

        RefundRecorded::dispatch($refund);

        $row = DB::table('domain_logs')->where('channel', 'commerce')->latest('id')->first();
        $this->assertSame('user', $row->actor_type);
        $this->assertSame('42', $row->actor_id);
        $this->assertSame('Jelte', $row->actor_label);
    }

    private function paymentWithReference(string $reference, PaymentStatus $status): Payment
    {
        $payable = TestPayable::factory()->create(['total_due' => 2500]);

        return Payment::factory()->for($payable, 'payable')->create([
            'gateway' => 'fake',
            'gateway_reference' => $reference,
            'amount' => 2500,
            'currency' => Currency::EUR,
            'status' => $status,
        ]);
    }
}
