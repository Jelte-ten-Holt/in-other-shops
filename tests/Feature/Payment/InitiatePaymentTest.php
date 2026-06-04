<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Payment;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Payment\Actions\InitiatePayment;
use InOtherShops\Payment\Concerns\InteractsWithPaymentProfiles;
use InOtherShops\Payment\Contracts\HasPaymentProfiles;
use InOtherShops\Payment\Contracts\HasPayments;
use InOtherShops\Payment\Contracts\PaymentGateway;
use InOtherShops\Payment\DTOs\PaymentCustomerData;
use InOtherShops\Payment\DTOs\PaymentSession;
use InOtherShops\Payment\DTOs\WebhookPayload;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Payment\Models\PaymentProfile;
use InOtherShops\Payment\PaymentGatewayManager;
use InOtherShops\Payment\Testing\FakePaymentGateway;
use InOtherShops\Tests\Stubs\TestPayable;
use InOtherShops\Tests\TestCase;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

final class InitiatePaymentTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $gateway;

    private InitiatePayment $initiate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakePaymentGateway('fake');
        $this->app->make(PaymentGatewayManager::class)
            ->extend('fake', fn (): FakePaymentGateway => $this->gateway);

        $this->initiate = $this->app->make(InitiatePayment::class);
    }

    #[Test]
    public function it_creates_a_pending_payment_record_attached_to_the_payable(): void
    {
        $payable = TestPayable::factory()->create(['total_due' => 5000]);

        $result = ($this->initiate)(
            gatewayName: 'fake',
            payable: $payable,
            amount: 5000,
            currency: Currency::EUR,
            returnUrl: '/return',
            cancelUrl: '/cancel',
        );

        $this->assertSame(1, Payment::query()->count());
        $payment = $result->payment;
        $this->assertSame(5000, $payment->amount);
        $this->assertSame(Currency::EUR, $payment->currency);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame('fake', $payment->gateway);
        $this->assertTrue($payment->payable->is($payable));
    }

    #[Test]
    public function it_writes_back_the_gateway_reference_and_data_after_session_create(): void
    {
        // Pre-session the record has no reference; post-session it must.
        // A regression that skipped the update step would leave gateway_reference
        // NULL and the buyer would have no way to resume the session.
        $payable = TestPayable::factory()->create();

        $result = ($this->initiate)(
            gatewayName: 'fake',
            payable: $payable,
            amount: 100,
            currency: Currency::EUR,
            returnUrl: '/r',
            cancelUrl: '/c',
        );

        $payment = $result->payment->fresh();
        $this->assertNotNull($payment->gateway_reference);
        $this->assertStringStartsWith('fake_pi_', $payment->gateway_reference);
        $this->assertIsArray($payment->gateway_data);
        $this->assertArrayHasKey('payment_intent_status', $payment->gateway_data);
    }

    #[Test]
    public function the_returned_result_carries_the_redirect_url_and_client_secret(): void
    {
        $payable = TestPayable::factory()->create();

        $result = ($this->initiate)(
            gatewayName: 'fake',
            payable: $payable,
            amount: 100,
            currency: Currency::EUR,
            returnUrl: '/r',
            cancelUrl: '/c',
        );

        // FakePaymentGateway returns null redirectUrl + a deterministic
        // client secret; either could be transformed by the action.
        $this->assertSame($result->payment->gateway_reference.'_secret', $result->clientSecret);
        $this->assertNull($result->redirectUrl);
    }

    #[Test]
    public function it_passes_return_and_cancel_urls_through_to_the_gateway(): void
    {
        // The gateway's session creator is the only place these URLs actually
        // matter. Asserting via recordedSessions() rather than the result's
        // own fields proves they reached the gateway, not just that they
        // round-tripped through the action.
        $payable = TestPayable::factory()->create();

        ($this->initiate)(
            gatewayName: 'fake',
            payable: $payable,
            amount: 100,
            currency: Currency::EUR,
            returnUrl: 'https://shop.test/return',
            cancelUrl: 'https://shop.test/cancel',
        );

        $sessions = $this->gateway->recordedSessions();
        $this->assertCount(1, $sessions);
        $this->assertSame('https://shop.test/return', $sessions[0]['returnUrl']);
        $this->assertSame('https://shop.test/cancel', $sessions[0]['cancelUrl']);
    }

    #[Test]
    public function it_passes_no_gateway_customer_id_when_no_profileable_is_provided(): void
    {
        $payable = TestPayable::factory()->create();

        ($this->initiate)(
            gatewayName: 'fake',
            payable: $payable,
            amount: 100,
            currency: Currency::EUR,
            returnUrl: '/r',
            cancelUrl: '/c',
        );

        $this->assertNull($this->gateway->recordedSessions()[0]['gatewayCustomerId']);
        $this->assertCount(0, $this->gateway->recordedCustomers(),
            'No profileable → no customer creation call.');
    }

    #[Test]
    public function it_reuses_an_existing_payment_profile_without_creating_a_new_gateway_customer(): void
    {
        $payable = TestPayable::factory()->create();
        $profileable = $this->makeProfileable();
        $profileable->paymentProfiles()->create([
            'gateway' => 'fake',
            'gateway_customer_id' => 'fake_cust_existing',
        ]);

        ($this->initiate)(
            gatewayName: 'fake',
            payable: $payable,
            amount: 100,
            currency: Currency::EUR,
            returnUrl: '/r',
            cancelUrl: '/c',
            profileable: $profileable,
            customerData: new PaymentCustomerData(email: 'should@not.fire', name: 'Ignored'),
        );

        $this->assertSame('fake_cust_existing', $this->gateway->recordedSessions()[0]['gatewayCustomerId']);
        $this->assertCount(0, $this->gateway->recordedCustomers(),
            'Existing profile must short-circuit customer creation, even if customerData is passed.');
        $this->assertSame(1, $profileable->paymentProfiles()->count(),
            'No second PaymentProfile row should be written.');
    }

    #[Test]
    public function it_creates_a_new_gateway_customer_and_persists_a_profile_when_no_profile_exists(): void
    {
        $payable = TestPayable::factory()->create();
        $profileable = $this->makeProfileable();

        ($this->initiate)(
            gatewayName: 'fake',
            payable: $payable,
            amount: 100,
            currency: Currency::EUR,
            returnUrl: '/r',
            cancelUrl: '/c',
            profileable: $profileable,
            customerData: new PaymentCustomerData(email: 'new@buyer.test', name: 'New Buyer'),
        );

        $recordedCustomers = $this->gateway->recordedCustomers();
        $this->assertCount(1, $recordedCustomers);
        $this->assertSame('new@buyer.test', $recordedCustomers[0]->email);

        $profile = $profileable->paymentProfiles()->first();
        $this->assertNotNull($profile);
        $this->assertSame('fake', $profile->gateway);
        $this->assertStringStartsWith('fake_cust_', $profile->gateway_customer_id);
        $this->assertSame($profile->gateway_customer_id, $this->gateway->recordedSessions()[0]['gatewayCustomerId']);
    }

    #[Test]
    public function it_does_not_create_a_customer_when_profileable_has_no_profile_and_customer_data_is_null(): void
    {
        // The OR-branch: profileable present, profile missing, customerData
        // null → gateway customer id stays null. An implementation that
        // unconditionally called createCustomer when profileable was present
        // would fail here.
        $payable = TestPayable::factory()->create();
        $profileable = $this->makeProfileable();

        ($this->initiate)(
            gatewayName: 'fake',
            payable: $payable,
            amount: 100,
            currency: Currency::EUR,
            returnUrl: '/r',
            cancelUrl: '/c',
            profileable: $profileable,
            // customerData omitted
        );

        $this->assertCount(0, $this->gateway->recordedCustomers());
        $this->assertNull($this->gateway->recordedSessions()[0]['gatewayCustomerId']);
        $this->assertSame(0, $profileable->paymentProfiles()->count());
    }

    #[Test]
    public function it_does_not_create_a_customer_when_the_gateway_does_not_manage_customers(): void
    {
        // Register a separate gateway that implements PaymentGateway but not
        // ManagesCustomers. Even with customerData present and no profile on
        // the profileable, the action must skip createCustomer because the
        // gateway has no such method.
        $basicGateway = new BasicPaymentGateway;
        $this->app->make(PaymentGatewayManager::class)
            ->extend('basic', fn (): BasicPaymentGateway => $basicGateway);

        $payable = TestPayable::factory()->create();
        $profileable = $this->makeProfileable();

        ($this->initiate)(
            gatewayName: 'basic',
            payable: $payable,
            amount: 100,
            currency: Currency::EUR,
            returnUrl: '/r',
            cancelUrl: '/c',
            profileable: $profileable,
            customerData: new PaymentCustomerData(email: 'a@b.test', name: 'A'),
        );

        $this->assertSame(0, $profileable->paymentProfiles()->count(),
            'Non-ManagesCustomers gateway → no profile row should be written.');
        $this->assertNull($basicGateway->lastCustomerId);
    }

    #[Test]
    public function it_throws_invalid_argument_exception_for_an_unregistered_gateway_name(): void
    {
        $payable = TestPayable::factory()->create();

        try {
            ($this->initiate)(
                gatewayName: 'mystery',
                payable: $payable,
                amount: 100,
                currency: Currency::EUR,
                returnUrl: '/r',
                cancelUrl: '/c',
            );
            $this->fail('Expected InvalidArgumentException for unregistered gateway.');
        } catch (\InvalidArgumentException) {
            // Critical: prove the guard prevented the side effect, not just
            // that the exception fired after writing.
            $this->assertSame(0, Payment::query()->count(),
                'No Payment row should be written when the gateway lookup fails.');
        }
    }

    private function makeProfileable(): TestProfileable
    {
        return TestProfileable::query()->create(['name' => 'Buyer']);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::create('test_profileables', function ($table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Relation::morphMap(['test_profileable' => TestProfileable::class]);
    }
}

/**
 * Minimal model implementing HasPaymentProfiles. Defined alongside the test
 * so the package's own stubs don't have to absorb a payment-only fixture.
 */
final class TestProfileable extends Model implements HasPaymentProfiles
{
    use HasFactory;
    use InteractsWithPaymentProfiles;

    protected $guarded = [];

    protected $table = 'test_profileables';

    protected function casts(): array
    {
        return [];
    }
}

/**
 * Minimal PaymentGateway that does NOT implement ManagesCustomers. Used to
 * probe the `instanceof ManagesCustomers` branch in InitiatePayment without
 * fighting the FakePaymentGateway type hierarchy.
 */
final class BasicPaymentGateway implements PaymentGateway
{
    public ?string $lastCustomerId = null;

    public function identifier(): string
    {
        return 'basic';
    }

    public function createSession(Payment $payment, string $returnUrl, string $cancelUrl, ?string $gatewayCustomerId = null): PaymentSession
    {
        $this->lastCustomerId = $gatewayCustomerId;

        return new PaymentSession(
            gatewayReference: 'basic_ref_'.$payment->id,
            clientSecret: 'basic_secret_'.$payment->id,
            gatewayData: ['driver' => 'basic'],
        );
    }

    public function retrieveSession(Payment $payment): PaymentSession
    {
        return new PaymentSession(
            gatewayReference: $payment->gateway_reference ?? 'basic_ref',
            clientSecret: 'basic_secret',
            gatewayData: [],
        );
    }

    public function verifyWebhookSignature(Request $request): void {}

    public function parseWebhook(Request $request): WebhookPayload
    {
        return new WebhookPayload(
            gatewayReference: 'basic_ref',
            status: PaymentStatus::Pending,
            eventId: null,
            gatewayData: [],
            amount: null,
            currency: null,
        );
    }

    public function refund(Payment $payment, ?int $amount = null): string
    {
        return 'fake_re_test';
    }

    public function customerDashboardUrl(string $gatewayCustomerId): ?string
    {
        return null;
    }

    public function paymentDashboardUrl(Payment $payment): ?string
    {
        return null;
    }
}
