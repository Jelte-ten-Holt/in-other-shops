<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Logging;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InOtherShops\Agent\AgentTool;
use InOtherShops\Commerce\Commerce;
use InOtherShops\Commerce\Order\Actions\UpdateOrderStatus;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\FlowChain\Contracts\FlowPayload;
use InOtherShops\FlowChain\FlowChain;
use InOtherShops\Inventory\Actions\AdjustStock;
use InOtherShops\Inventory\Enums\StockMovementReason;
use InOtherShops\Logging\Handlers\DatabaseLogHandler;
use InOtherShops\Payment\Actions\ProcessPaymentWebhook;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Payment\PaymentGatewayManager;
use InOtherShops\Payment\Testing\FakePaymentGateway;
use InOtherShops\Purchasing\Actions\CreatePurchaseOrder;
use InOtherShops\Purchasing\Actions\PlacePurchaseOrder;
use InOtherShops\Purchasing\Actions\ReceiveItems;
use InOtherShops\Purchasing\Models\Supplier;
use InOtherShops\Shipping\Actions\CreateShipment;
use InOtherShops\Shipping\DTOs\ShippingMethod;
use InOtherShops\Tests\Stubs\TestPayable;
use InOtherShops\Tests\Stubs\TestPurchasable;
use InOtherShops\Tests\Stubs\TestStockable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Closes the G3 blind spot for the stock-moving channels: every other audit
 * test fakes the event bus (`Event::fake` + `assertDispatched`), so the REAL
 * subscriber never runs and nothing ever asserts a `domain_logs` row through
 * the dispatch → subscribe → handler → row path. A typo in a subscribe() map, a
 * wrong event class-string, an unregistered channel, or a `LogEntry`/column
 * mismatch would all pass green while writing nothing.
 *
 * These tests run the real actions with NO event faking and the channel routed
 * to the real `DatabaseLogHandler` (the shipped config sends every channel to
 * the file handler), then assert the row actually landed with its context.
 *
 * Every audit channel is now covered end-to-end (G3 closed): the stock channels
 * (purchasing/inventory) plus commerce, payment, shipping, flowchain, and agent.
 * Each test routes its channel to the real `DatabaseLogHandler` in
 * {@see defineEnvironment}, runs the real action/path with NO event faking, and
 * asserts the row landed — so a broken subscribe() map, a wrong event
 * class-string, an unregistered channel, or a LogEntry/column mismatch fails
 * loud instead of green. Add the same shape when a new audit channel is born.
 */
final class AuditPipelineRowTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Must be set before the domain providers boot — subscribers capture the
        // LogDispatcher (and thus its handler map) at subscribe time.
        foreach (['purchasing', 'inventory', 'commerce', 'payment', 'shipping', 'flowchain', 'agent'] as $channel) {
            $app['config']->set("domain-log.channels.{$channel}", [
                ['handler' => DatabaseLogHandler::class, 'with' => []],
            ]);
        }
    }

    #[Test]
    public function receiving_purchase_items_writes_a_purchasing_audit_row_with_context(): void
    {
        $supplier = Supplier::factory()->create();
        $book = TestPurchasable::factory()->create();

        $order = app(CreatePurchaseOrder::class)(
            supplier: $supplier,
            lines: [['purchasable' => $book, 'quantity_ordered' => 10, 'unit_cost' => 500]],
        );
        app(PlacePurchaseOrder::class)($order);
        $lineId = $order->lines()->first()->id;

        app(ReceiveItems::class)($order->refresh(), [$lineId => 6]);

        // The real PurchasingLogSubscriber ran and wrote through DatabaseLogHandler.
        $row = DB::table('domain_logs')
            ->where('channel', 'purchasing')
            ->where('message', 'like', 'Items received%')
            ->first();

        $this->assertNotNull($row, 'No purchasing audit row was written for the receipt.');
        $this->assertSame('info', $row->level);

        $context = json_decode((string) $row->context, true);
        $this->assertIsArray($context);
        $this->assertSame($order->id, $context['purchase_order_id']);
        $this->assertContains(6, $context['received'], 'Receipt quantity missing from the audit context.');
    }

    #[Test]
    public function adjusting_stock_writes_an_inventory_audit_row(): void
    {
        $widget = TestStockable::factory()->create();

        app(AdjustStock::class)($widget, 5, StockMovementReason::Received);

        $row = DB::table('domain_logs')->where('channel', 'inventory')->first();

        $this->assertNotNull($row, 'No inventory audit row was written for the stock adjustment.');
        $this->assertSame('info', $row->level);
        $this->assertNotSame('false', $row->context, 'Context was stored as the literal false (G10).');
    }

    #[Test]
    public function a_row_written_with_no_boundary_actor_records_unknown_not_null(): void
    {
        // No boundary set an actor (a bare action call), so the row must carry
        // the loud `unknown` default through the real pipeline — never a null
        // hole in the actor columns (F21).
        $widget = TestStockable::factory()->create();

        app(AdjustStock::class)($widget, 5, StockMovementReason::Received);

        $row = DB::table('domain_logs')->where('channel', 'inventory')->first();

        $this->assertSame('system', $row->actor_type);
        $this->assertNull($row->actor_id);
        $this->assertSame('unknown', $row->actor_label);
    }

    #[Test]
    public function the_ambient_boundary_actor_is_stamped_on_the_row_through_the_real_pipeline(): void
    {
        // Set an actor at the "boundary" and prove it survives the full
        // dispatch → subscribe → handler → row path onto the actor columns.
        app(\InOtherShops\Logging\LogContext::class)
            ->setActor(\InOtherShops\Logging\DTOs\LogActor::agent('hash123', 'oauth:7'));

        $widget = TestStockable::factory()->create();
        app(AdjustStock::class)($widget, 5, StockMovementReason::Received);

        $row = DB::table('domain_logs')->where('channel', 'inventory')->first();

        $this->assertSame('agent', $row->actor_type);
        $this->assertSame('hash123', $row->actor_id);
        $this->assertSame('oauth:7', $row->actor_label);
    }

    #[Test]
    public function changing_an_order_status_writes_a_commerce_audit_row(): void
    {
        $order = Commerce::order()::factory()->create(['status' => OrderStatus::Pending]);

        app(UpdateOrderStatus::class)($order, OrderStatus::Cancelled);

        $row = DB::table('domain_logs')->where('channel', 'commerce')->latest('id')->first();

        $this->assertNotNull($row, 'No commerce audit row was written for the status change.');
        $this->assertSame('info', $row->level);
    }

    #[Test]
    public function a_payment_webhook_writes_a_payment_audit_row(): void
    {
        $gateway = new FakePaymentGateway('fake');
        $this->app->make(PaymentGatewayManager::class)
            ->extend('fake', fn (): FakePaymentGateway => $gateway);

        $payable = TestPayable::factory()->create(['total_due' => 2500]);
        $payment = Payment::factory()->for($payable, 'payable')->create([
            'gateway' => 'fake',
            'gateway_reference' => 'fake_pi_pipeline',
            'amount' => 2500,
            'currency' => Currency::EUR,
            'status' => PaymentStatus::Pending,
        ]);
        $request = $gateway->simulateWebhook($payment, PaymentStatus::Succeeded, 'evt_pipeline');

        $this->app->make(ProcessPaymentWebhook::class)('fake', $request);

        $row = DB::table('domain_logs')->where('channel', 'payment')->latest('id')->first();

        $this->assertNotNull($row, 'No payment audit row was written for the webhook.');
    }

    #[Test]
    public function creating_a_shipment_writes_a_shipping_audit_row(): void
    {
        $order = Commerce::order()::factory()->create();
        $method = new ShippingMethod(
            identifier: 'standard',
            name: 'Standard',
            sortOrder: 0,
            isActive: true,
            rates: [],
        );

        app(CreateShipment::class)($order, $method, collect());

        $row = DB::table('domain_logs')->where('channel', 'shipping')->latest('id')->first();

        $this->assertNotNull($row, 'No shipping audit row was written for the shipment.');
    }

    #[Test]
    public function running_a_flow_chain_writes_a_flowchain_audit_row(): void
    {
        FlowChain::make()->name('audit-pipeline-probe')->run(new AuditProbePayload);

        $row = DB::table('domain_logs')->where('channel', 'flowchain')->latest('id')->first();

        $this->assertNotNull($row, 'No flowchain audit row was written for the chain run.');
    }

    #[Test]
    public function invoking_an_agent_tool_writes_an_agent_audit_row(): void
    {
        $tool = new class extends AgentTool
        {
            public static function identifier(): string
            {
                return 'audit_probe';
            }

            public static function displayName(): string
            {
                return 'Audit probe';
            }

            public function description(): string
            {
                return 'Probe tool for the audit-pipeline row test.';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object'];
            }

            public function __invoke(array $arguments): array
            {
                return ['ok' => true];
            }
        };

        $tool->execute([]);

        $row = DB::table('domain_logs')->where('channel', 'agent')->latest('id')->first();

        $this->assertNotNull($row, 'No agent audit row was written for the tool invocation.');
    }
}

/**
 * Trivial payload for the flow-chain pipeline probe — FlowPayload is a marker
 * contract, so an empty implementation is all a chain run needs.
 */
final class AuditProbePayload implements FlowPayload {}
