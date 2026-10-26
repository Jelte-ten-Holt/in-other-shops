<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Agent;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use InOtherShops\Agent\Http\Middleware\AuthenticateAgent;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Commerce\Order\Models\OrderLine;
use InOtherShops\Commerce\Order\Models\Refund;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Logging\Enums\LogActorType;
use InOtherShops\Logging\LogContext;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Purchasing\Models\PurchaseOrder;
use InOtherShops\Shipping\Models\Shipment;
use InOtherShops\Shipping\Models\ShipmentItem;
use InOtherShops\Tests\Stubs\TestPayable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The read API's own rules, beyond the fixtures: who gets in, what a page
 * accepts, how the keyset pages, and what each resource leaves out.
 */
final class ReadApiTest extends TestCase
{
    use RefreshDatabase;

    private const string READ_BEARER = 'read-api-test-bearer';

    private const string OPERATOR_BEARER = 'operator-bearer-for-mcp';

    private const string UNTIL = '2026-12-31T00:00:00Z';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('agent.read.enabled', true);
        $app['config']->set('agent.read.bearer_token', self::READ_BEARER);
        $app['config']->set('agent.auth.bearer_token', self::OPERATOR_BEARER);
    }

    // ── Who gets in ─────────────────────────────────────────────────────

    #[Test]
    public function no_bearer_is_unauthorized_with_a_plain_bearer_challenge(): void
    {
        $this->getJson($this->url('payments'))
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer');
    }

    #[Test]
    public function the_operator_bearer_does_not_open_the_read_api(): void
    {
        $this->withToken(self::OPERATOR_BEARER)->getJson($this->url('payments'))->assertUnauthorized();
    }

    #[Test]
    public function a_wrong_bearer_is_unauthorized(): void
    {
        $this->withToken('not-the-read-bearer')->getJson($this->url('payments'))->assertUnauthorized();
    }

    #[Test]
    public function the_read_bearer_opens_nothing_but_the_read_api(): void
    {
        Route::middleware([AuthenticateAgent::class])->get('/agent-test/mcp-surface', fn (): array => ['ok' => true]);

        $this->withToken(self::READ_BEARER)->getJson('/agent-test/mcp-surface')->assertUnauthorized();
    }

    #[Test]
    public function the_read_bearer_is_never_admin_and_is_the_audit_actor(): void
    {
        Route::middleware([AuthenticateAgent::class.':read'])->get('/agent-test/read-surface', fn (Request $request): array => [
            'is_admin' => $request->attributes->get('agent.is_admin'),
            'scopes' => $request->attributes->get('agent.scopes'),
        ]);

        $this->withToken(self::READ_BEARER)->getJson('/agent-test/read-surface')
            ->assertOk()
            ->assertExactJson(['is_admin' => false, 'scopes' => ['read']]);

        $actor = $this->app->make(LogContext::class)->actor();
        $this->assertSame(LogActorType::Agent, $actor?->type);
        $this->assertSame('read bearer', $actor->label);
    }

    #[Test]
    public function an_unset_read_bearer_fails_closed_even_for_an_empty_one(): void
    {
        config(['agent.read.bearer_token' => '']);

        $this->withToken('')->getJson($this->url('payments'))->assertUnauthorized();
        $this->withToken(self::READ_BEARER)->getJson($this->url('payments'))->assertUnauthorized();
    }

    #[Test]
    public function a_read_bearer_set_to_the_operator_bearer_fails_closed(): void
    {
        config(['agent.read.bearer_token' => self::OPERATOR_BEARER]);

        $this->withToken(self::OPERATOR_BEARER)->getJson($this->url('payments'))->assertUnauthorized();
    }

    #[Test]
    public function an_unknown_resource_is_not_found(): void
    {
        $this->withToken(self::READ_BEARER)->getJson($this->url('orders'))->assertNotFound();
    }

    // ── What a page accepts ─────────────────────────────────────────────

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidPages(): array
    {
        return [
            'no until' => [[], 'until'],
            'until without its Z' => [['until' => '2026-12-31T00:00:00'], 'until'],
            'a cursor time without its id' => [['until' => self::UNTIL, 'after_updated_at' => '2026-11-01T00:00:00Z'], 'after_id'],
            'a cursor id without its time' => [['until' => self::UNTIL, 'after_id' => 5], 'after_updated_at'],
            'a limit of nought' => [['until' => self::UNTIL, 'limit' => 0], 'limit'],
            'a limit over the maximum' => [['until' => self::UNTIL, 'limit' => 501], 'limit'],
        ];
    }

    /** @param  array<string, mixed>  $query */
    #[Test]
    #[DataProvider('invalidPages')]
    public function an_invalid_page_is_refused_as_json_naming_the_field(array $query, string $field): void
    {
        // No Accept header: a machine client still gets JSON, never a redirect.
        $this->withToken(self::READ_BEARER)
            ->get('/api/read/v1/payments?'.http_build_query($query))
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
    }

    // ── How the keyset pages ────────────────────────────────────────────

    #[Test]
    public function pages_follow_updated_at_then_id_and_resume_exactly_after_the_cursor(): void
    {
        $tie = '2026-11-10 12:00:00';
        $a = $this->payment(updatedAt: '2026-11-09 08:00:00');
        $b = $this->payment(updatedAt: $tie);
        $c = $this->payment(updatedAt: $tie);
        $d = $this->payment(updatedAt: '2026-11-11 08:00:00');

        $first = $this->page('payments', ['limit' => 2])->json();
        $this->assertSame([$a->id, $b->id], array_column($first['data'], 'id'));
        $this->assertSame(['updated_at' => '2026-11-10T12:00:00Z', 'id' => $b->id], $first['next']);
        $this->assertTrue($first['has_more']);

        $second = $this->page('payments', [
            'limit' => 2,
            'after_updated_at' => $first['next']['updated_at'],
            'after_id' => $first['next']['id'],
        ])->json();
        $this->assertSame([$c->id, $d->id], array_column($second['data'], 'id'));
        $this->assertFalse($second['has_more']);

        $empty = $this->page('payments', ['after_updated_at' => '2026-11-11T08:00:00Z', 'after_id' => $d->id])->json();
        $this->assertSame(['data' => [], 'next' => null, 'has_more' => false], $empty);
    }

    #[Test]
    public function until_bounds_every_page(): void
    {
        $in = $this->payment(updatedAt: '2026-11-10 12:00:00');
        $this->payment(updatedAt: '2026-11-10 12:00:01');

        $body = $this->page('payments', ['until' => '2026-11-10T12:00:00Z'])->json();

        $this->assertSame([$in->id], array_column($body['data'], 'id'));
        $this->assertFalse($body['has_more']);
    }

    #[Test]
    public function a_row_written_after_the_cursor_passed_it_comes_back_on_the_next_pull(): void
    {
        $payment = $this->payment(updatedAt: '2026-11-10 12:00:00');
        $first = $this->page('payments')->json();

        $payment->forceFill(['amount_refunded' => 500, 'updated_at' => CarbonImmutable::parse('2026-11-20 09:00:00')])->save();

        $next = $this->page('payments', ['after_updated_at' => $first['next']['updated_at'], 'after_id' => $first['next']['id']])->json();
        $this->assertSame([$payment->id], array_column($next['data'], 'id'));
        $this->assertSame(500, $next['data'][0]['amount_refunded']);
    }

    // ── What each resource leaves out ───────────────────────────────────

    #[Test]
    public function only_an_orders_payments_and_shipments_are_read(): void
    {
        $orderPayment = $this->payment(updatedAt: '2026-11-10 12:00:00');
        Payment::factory()->for(TestPayable::factory()->create(['total_due' => 1000]), 'payable')
            ->create(['amount' => 1000, 'updated_at' => '2026-11-10 12:00:00']);

        $this->assertSame([$orderPayment->id], array_column($this->page('payments')->json('data'), 'id'));
    }

    /**
     * The order link is polymorphic and has no foreign key, so an order row can
     * be gone under its payment or shipment. Such a row is skipped: failing its
     * page would stop the client's cursor there for good.
     */
    #[Test]
    public function a_payment_or_shipment_whose_order_is_gone_is_skipped_not_fatal(): void
    {
        $kept = $this->payment(updatedAt: '2026-11-10 12:00:00');
        Payment::factory()->succeeded()->create([
            'payable_type' => 'order', 'payable_id' => 999_999, 'amount' => 1000, 'gateway' => 'fake', 'updated_at' => '2026-11-10 12:00:00',
        ]);
        Shipment::factory()->create(['shippable_type' => 'order', 'shippable_id' => 999_999, 'updated_at' => '2026-11-10 12:00:00']);

        $this->assertSame([$kept->id], array_column($this->page('payments')->json('data'), 'id'));
        $this->assertSame([], $this->page('shipments')->json('data'));
    }

    #[Test]
    public function an_orders_notes_and_a_payments_gateway_data_are_not_sent(): void
    {
        $payment = $this->payment(updatedAt: '2026-11-10 12:00:00');
        $payment->update(['gateway_data' => ['secret' => 'x']]);
        $payment->payable->update(['notes' => 'internal']);

        $item = $this->page('payments')->json('data.0');

        $this->assertArrayNotHasKey('gateway_data', $item);
        $this->assertArrayNotHasKey('notes', $item['order']);
    }

    #[Test]
    public function a_refund_carries_its_payments_currency_or_else_its_orders(): void
    {
        // A payment in another currency than its order, so the two sources differ.
        $payment = $this->payment(updatedAt: '2026-11-10 12:00:00', currency: Currency::USD);
        $payment->payable->forceFill(['currency' => Currency::EUR])->saveQuietly();
        Refund::factory()->create(['order_id' => $payment->payable_id, 'payment_id' => $payment->id, 'updated_at' => '2026-11-12 10:00:00']);
        $order = Order::factory()->create(['currency' => Currency::GBP]);
        Refund::factory()->create(['order_id' => $order->id, 'payment_id' => null, 'updated_at' => '2026-11-12 11:00:00']);

        $this->assertSame(['USD', 'GBP'], array_column($this->page('refunds')->json('data'), 'currency'));
    }

    #[Test]
    public function a_shipment_carries_its_own_share_of_a_split_order_not_the_orders_total(): void
    {
        $order = Order::factory()->create([
            'total' => 7963,
            'tax_summary' => [
                ['rate_bps' => 700, 'taxable_base' => 5233, 'tax' => 366],
                ['rate_bps' => 1900, 'taxable_base' => 1987, 'tax' => 377],
            ],
        ]);
        OrderLine::factory()->create(['order_id' => $order->id, 'line_total' => 5500, 'tax_rate_bps' => 700]);
        $poster = OrderLine::factory()->create(['order_id' => $order->id, 'line_total' => 2400, 'tax_rate_bps' => 1900]);
        $shipment = Shipment::factory()->for($order, 'shippable')->create(['updated_at' => '2026-11-10 12:00:00']);
        ShipmentItem::factory()->create(['shipment_id' => $shipment->id, 'order_line_id' => $poster->id, 'quantity' => 1]);

        $item = $this->page('shipments')->json('data.0');

        $this->assertSame(2364, $item['amount']);
        $this->assertSame([['rate_bps' => 1900, 'taxable_base' => 1987, 'tax' => 377]], $item['tax_summary']);
    }

    #[Test]
    public function a_purchase_order_without_an_invoice_reads_as_one(): void
    {
        PurchaseOrder::factory()->create(['updated_at' => '2026-11-10 12:00:00']);

        $item = $this->page('purchase_orders')->json('data.0');

        $this->assertNull($item['supplier_invoice_number']);
        $this->assertNull($item['supplier_invoice_tax_summary']);
        $this->assertNull($item['paid_at']);
        $this->assertNull($item['supplier']['address']);
    }

    #[Test]
    public function a_shipment_of_something_other_than_an_order_is_not_read(): void
    {
        $order = Order::factory()->create();
        $ofOrder = Shipment::factory()->for($order, 'shippable')->create(['updated_at' => '2026-11-10 12:00:00']);
        Shipment::factory()->for(TestPayable::factory()->create(['total_due' => 100]), 'shippable')->create(['updated_at' => '2026-11-10 12:00:00']);

        $this->assertSame([$ofOrder->id], array_column($this->page('shipments')->json('data'), 'id'));
    }

    private function payment(string $updatedAt, Currency $currency = Currency::EUR): Payment
    {
        $order = Order::factory()->create(['currency' => $currency, 'total' => 1000]);

        return Payment::factory()->succeeded()->for($order, 'payable')->create([
            'amount' => 1000,
            'currency' => $currency,
            'gateway' => 'fake',
            'updated_at' => $updatedAt,
        ]);
    }

    /** @param  array<string, mixed>  $query */
    private function page(string $resource, array $query = []): \Illuminate\Testing\TestResponse
    {
        return $this->withToken(self::READ_BEARER)->getJson($this->url($resource, $query))->assertOk();
    }

    /** @param  array<string, mixed>  $query */
    private function url(string $resource, array $query = []): string
    {
        return '/api/read/v1/'.$resource.'?'.http_build_query(['until' => self::UNTIL, ...$query]);
    }
}
