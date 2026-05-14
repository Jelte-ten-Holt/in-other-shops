<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Logging;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Agent\DTOs\ToolInvocation;
use InOtherShops\Agent\Events\DynamicClientRegistered;
use InOtherShops\Agent\Events\ToolInvocationFailed;
use InOtherShops\Agent\Events\ToolInvoked;
use InOtherShops\Commerce\Cart\Events\CartClaimed;
use InOtherShops\Commerce\Cart\Events\CartCleared;
use InOtherShops\Commerce\Cart\Events\CartUpdated;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Commerce\Customer\Models\Customer;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Events\OrderCreated;
use InOtherShops\Commerce\Order\Events\OrderStatusChanged;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\FlowChain\Contracts\FlowPayload;
use InOtherShops\FlowChain\DTOs\FlowChainResult;
use InOtherShops\FlowChain\Enums\FlowChainStatus;
use InOtherShops\FlowChain\Events\FlowChainCompleted;
use InOtherShops\FlowChain\Events\FlowChainFailed;
use InOtherShops\FlowChain\Events\FlowChainStarted;
use InOtherShops\FlowChain\Events\FlowChainStepFailed;
use InOtherShops\Inventory\Enums\StockMovementReason;
use InOtherShops\Inventory\Events\ReservationConfirmed;
use InOtherShops\Inventory\Events\ReservationCreated;
use InOtherShops\Inventory\Events\ReservationReleased;
use InOtherShops\Inventory\Events\StockAdjusted;
use InOtherShops\Inventory\Events\StockReleased;
use InOtherShops\Inventory\Events\StockReservationFailed;
use InOtherShops\Inventory\Models\StockItem;
use InOtherShops\Inventory\Models\StockMovement;
use InOtherShops\Inventory\Models\StockReservation;
use InOtherShops\Logging\DTOs\LogEntry;
use InOtherShops\Logging\Enums\LogLevel;
use InOtherShops\Logging\LogDispatcher;
use InOtherShops\Payment\Events\PaymentFailed;
use InOtherShops\Payment\Events\PaymentRefunded;
use InOtherShops\Payment\Events\PaymentSucceeded;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Pricing\Enums\VoucherType;
use InOtherShops\Pricing\Events\CompareAtPriceExpired;
use InOtherShops\Pricing\Events\PriceCreated;
use InOtherShops\Pricing\Events\PriceDeleted;
use InOtherShops\Pricing\Events\PriceUpdated;
use InOtherShops\Pricing\Events\VoucherApplied;
use InOtherShops\Pricing\Models\Price;
use InOtherShops\Pricing\Models\Voucher;
use InOtherShops\Shipping\Enums\ShipmentStatus;
use InOtherShops\Shipping\Events\ShipmentCreated;
use InOtherShops\Shipping\Events\ShipmentDelivered;
use InOtherShops\Shipping\Events\ShipmentDispatched;
use InOtherShops\Shipping\Events\ShipmentLost;
use InOtherShops\Shipping\Events\ShipmentReady;
use InOtherShops\Shipping\Events\ShipmentReturnedToSender;
use InOtherShops\Shipping\Models\Shipment;
use InOtherShops\Tests\Stubs\RecordingLogHandler;
use InOtherShops\Tests\Stubs\TestStockable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Verifies the package's seven log subscribers each route their subscribed
 * events to the right channel at the right level with the right context
 * shape. Without this test the per-subscriber event→LogEntry mapping is
 * silent — a regression that drops a context key, mis-routes a channel,
 * or downgrades a level would only surface in production logs.
 *
 * Pricing's CHANNEL is intentionally 'commerce' (not 'pricing'). See
 * PricingLogSubscriber — voucher/price events flow into the commerce
 * channel so a single channel covers the customer-facing money trail.
 */
final class LogSubscriberMappingTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Bind a single recording handler instance and route every channel
        // through it. Each test reads back the captured entries and asserts
        // channel + level + context shape.
        $app->singleton(RecordingLogHandler::class);

        $channelMap = [
            'handler' => RecordingLogHandler::class,
            'with' => [],
        ];

        $app['config']->set('domain-log.channels', [
            'commerce' => [$channelMap],
            'inventory' => [$channelMap],
            'payment' => [$channelMap],
            'agent' => [$channelMap],
            'flowchain' => [$channelMap],
            'shipping' => [$channelMap],
        ]);

        $app['config']->set('domain-log.default', [$channelMap]);

        // The LogDispatcher singleton bakes in handler instances during register().
        // Force the rebuild so the override takes effect after the override.
        $app->forgetInstance(LogDispatcher::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->recorder()->reset();
    }

    private function recorder(): RecordingLogHandler
    {
        return $this->app->make(RecordingLogHandler::class);
    }

    private function assertSingleEntry(string $channel, LogLevel $level, string $messageContains): LogEntry
    {
        $this->assertCount(1, $this->recorder()->entries(),
            'Expected exactly one log entry; got '.count($this->recorder()->entries()).'.');

        $entry = $this->recorder()->lastEntry();

        $this->assertSame($channel, $entry->channel,
            "Entry routed to wrong channel: expected '{$channel}', got '{$entry->channel}'.");
        $this->assertSame($level, $entry->level,
            "Entry has wrong level: expected {$level->value}, got {$entry->level->value}.");
        $this->assertStringContainsString($messageContains, $entry->message,
            "Entry message does not contain '{$messageContains}'.");

        return $entry;
    }

    // ─────────────────────────────────────────────────────────────────
    // Inventory
    // ─────────────────────────────────────────────────────────────────

    #[Test]
    public function stock_adjusted_event_routes_to_inventory_channel_at_info(): void
    {
        $stockable = TestStockable::factory()->create();
        $stockItem = StockItem::factory()->for($stockable, 'stockable')->withLevel(7)->create();
        $movement = StockMovement::factory()->for($stockItem)->create([
            'quantity' => 3,
            'reason' => StockMovementReason::Restock,
            'source' => 'test',
        ]);

        StockAdjusted::dispatch($movement, $stockItem);

        $entry = $this->assertSingleEntry('inventory', LogLevel::Info, 'Stock adjusted');
        $this->assertSame($stockItem->id, $entry->context['stock_item_id']);
        $this->assertSame(3, $entry->context['quantity']);
        $this->assertSame('restock', $entry->context['reason']);
    }

    #[Test]
    public function stock_released_event_routes_to_inventory_channel_at_info(): void
    {
        $stockable = TestStockable::factory()->create();
        $stockItem = StockItem::factory()->for($stockable, 'stockable')->create();
        $reserveMovement = StockMovement::factory()->for($stockItem)->create(['quantity' => -2]);
        $reservation = StockReservation::factory()
            ->for($stockItem)
            ->create(['reserve_movement_id' => $reserveMovement->id]);
        $releaseMovement = StockMovement::factory()->for($stockItem)->create(['quantity' => 2]);

        StockReleased::dispatch($reservation, $releaseMovement);

        $entry = $this->assertSingleEntry('inventory', LogLevel::Info, 'Stock release');
        $this->assertSame($reservation->id, $entry->context['reservation_id']);
        $this->assertSame($releaseMovement->id, $entry->context['release_movement_id']);
    }

    #[Test]
    public function stock_reservation_failed_routes_to_inventory_channel_at_warning(): void
    {
        $stockable = TestStockable::factory()->create();
        StockItem::factory()->for($stockable, 'stockable')->create();

        StockReservationFailed::dispatch($stockable, 5, 2);

        $entry = $this->assertSingleEntry('inventory', LogLevel::Warning, 'Reservation failed');
        $this->assertSame(5, $entry->context['requested_quantity']);
        $this->assertSame(2, $entry->context['available_quantity']);
    }

    #[Test]
    public function reservation_lifecycle_events_route_to_inventory_channel_at_info(): void
    {
        $stockable = TestStockable::factory()->create();
        $stockItem = StockItem::factory()->for($stockable, 'stockable')->create();
        $reserveMovement = StockMovement::factory()->for($stockItem)->create(['quantity' => -1]);
        $reservation = StockReservation::factory()
            ->for($stockItem)
            ->create(['reserve_movement_id' => $reserveMovement->id]);

        ReservationCreated::dispatch($reservation);
        ReservationConfirmed::dispatch($reservation);
        ReservationReleased::dispatch($reservation);

        $entries = $this->recorder()->entriesForChannel('inventory');
        $this->assertCount(3, $entries);

        $messages = array_map(fn (LogEntry $e) => $e->message, $entries);
        $this->assertContains('Reservation created.', $messages);
        $this->assertContains('Reservation confirmed.', $messages);
        $this->assertContains('Reservation released.', $messages);

        foreach ($entries as $entry) {
            $this->assertSame(LogLevel::Info, $entry->level);
            $this->assertSame($reservation->id, $entry->context['reservation_id']);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // Payment
    // ─────────────────────────────────────────────────────────────────

    #[Test]
    public function payment_succeeded_routes_to_payment_channel_at_info(): void
    {
        $payment = $this->makePayment();

        PaymentSucceeded::dispatch($payment);

        $entry = $this->assertSingleEntry('payment', LogLevel::Info, 'Payment succeeded');
        $this->assertSame($payment->id, $entry->context['payment_id']);
        $this->assertSame($payment->gateway, $entry->context['gateway']);
    }

    #[Test]
    public function payment_failed_routes_to_payment_channel_at_error(): void
    {
        $payment = $this->makePayment();

        PaymentFailed::dispatch($payment);

        $this->assertSingleEntry('payment', LogLevel::Error, 'Payment failed');
    }

    #[Test]
    public function payment_refunded_routes_to_payment_channel_at_info_with_amount_refunded(): void
    {
        $payment = $this->makePayment(['amount_refunded' => 250]);

        PaymentRefunded::dispatch($payment);

        $entry = $this->assertSingleEntry('payment', LogLevel::Info, 'Payment refunded');
        $this->assertSame(250, $entry->context['amount_refunded']);
    }

    private function makePayment(array $attrs = []): Payment
    {
        return Payment::factory()->create(array_merge([
            'gateway' => 'fake',
            'amount' => 1000,
            'currency' => Currency::EUR,
            'payable_type' => 'order',
            'payable_id' => 1,
        ], $attrs));
    }

    // ─────────────────────────────────────────────────────────────────
    // Pricing (channel: 'commerce' — intentional)
    // ─────────────────────────────────────────────────────────────────

    #[Test]
    public function voucher_applied_routes_to_commerce_channel_at_info(): void
    {
        $voucher = Voucher::factory()->create(['code' => 'TENOFF', 'type' => VoucherType::Fixed]);

        VoucherApplied::dispatch($voucher);

        $entry = $this->assertSingleEntry('commerce', LogLevel::Info, 'Voucher applied: TENOFF');
        $this->assertSame('TENOFF', $entry->context['code']);
        $this->assertSame('fixed', $entry->context['type']);
    }

    #[Test]
    public function price_created_routes_to_commerce_channel_at_info(): void
    {
        $price = Price::factory()->create([
            'priceable_type' => 'test_browsable',
            'priceable_id' => 1,
        ]);

        PriceCreated::dispatch($price);

        $entry = $this->assertSingleEntry('commerce', LogLevel::Info, 'Price created');
        $this->assertSame($price->id, $entry->context['price_id']);
        $this->assertSame('test_browsable', $entry->context['priceable_type']);
    }

    #[Test]
    public function price_updated_routes_to_commerce_channel_at_info(): void
    {
        $price = Price::factory()->create([
            'priceable_type' => 'test_browsable',
            'priceable_id' => 1,
        ]);

        PriceUpdated::dispatch($price);

        $this->assertSingleEntry('commerce', LogLevel::Info, 'Price updated');
    }

    #[Test]
    public function price_deleted_routes_to_commerce_channel_at_info(): void
    {
        // PriceDeleted carries (priceId, priceableType, priceableId) — the row
        // is gone by the time the event fires, so the handler logs from those
        // primitive values rather than re-querying the deleted Price.
        PriceDeleted::dispatch(42, 'test_browsable', 7);

        $entry = $this->assertSingleEntry('commerce', LogLevel::Info, 'Price deleted');
        $this->assertSame(42, $entry->context['price_id']);
        $this->assertSame('test_browsable', $entry->context['priceable_type']);
        $this->assertSame(7, $entry->context['priceable_id']);
    }

    #[Test]
    public function compare_at_price_expired_routes_to_commerce_channel_at_info(): void
    {
        // The strikethrough cutover changes the money field on a timer — the
        // log entry is what keeps that auditable rather than silent.
        $price = Price::factory()->create([
            'priceable_type' => 'test_browsable',
            'priceable_id' => 1,
            'amount' => 5000,
        ]);

        CompareAtPriceExpired::dispatch($price, 4000);

        $entry = $this->assertSingleEntry('commerce', LogLevel::Info, 'Strikethrough price expired');
        $this->assertSame($price->id, $entry->context['price_id']);
        $this->assertSame(4000, $entry->context['previous_amount']);
        $this->assertSame(5000, $entry->context['amount']);
    }

    // ─────────────────────────────────────────────────────────────────
    // Commerce (Cart + Order)
    // ─────────────────────────────────────────────────────────────────

    #[Test]
    public function cart_updated_routes_to_commerce_channel_at_info(): void
    {
        $cart = Cart::factory()->create();

        CartUpdated::dispatch($cart);

        $entry = $this->assertSingleEntry('commerce', LogLevel::Info, 'Cart updated');
        $this->assertSame($cart->id, $entry->context['cart_id']);
        $this->assertArrayHasKey('item_count', $entry->context);
    }

    #[Test]
    public function cart_claimed_routes_to_commerce_channel_at_info_with_new_owner(): void
    {
        $cart = Cart::factory()->create();
        $customer = Customer::factory()->create();

        CartClaimed::dispatch($cart, $customer);

        $entry = $this->assertSingleEntry('commerce', LogLevel::Info, 'Cart claimed');
        $this->assertSame($customer->getKey(), $entry->context['new_owner_id']);
        $this->assertSame($customer->getMorphClass(), $entry->context['new_owner_type']);
    }

    #[Test]
    public function cart_cleared_routes_to_commerce_channel_at_info(): void
    {
        $cart = Cart::factory()->create();

        CartCleared::dispatch($cart);

        $this->assertSingleEntry('commerce', LogLevel::Info, 'Cart cleared');
    }

    #[Test]
    public function order_created_routes_to_commerce_channel_at_info(): void
    {
        // The CreateShipmentForNewOrder listener also handles OrderCreated.
        // Disable auto-shipment so this test exercises only the log subscriber.
        config()->set('shipping.auto_create_shipment', false);

        $order = Order::factory()->create([
            'order_number' => 'TEST-LOG-1',
            'total' => 5000,
            'currency' => Currency::EUR,
        ]);

        OrderCreated::dispatch($order);

        $entry = $this->assertSingleEntry('commerce', LogLevel::Info, 'Order TEST-LOG-1 created');
        $this->assertSame('TEST-LOG-1', $entry->context['order_number']);
        $this->assertSame(5000, $entry->context['total']);
    }

    #[Test]
    public function order_status_changed_routes_to_commerce_channel_at_info(): void
    {
        $order = Order::factory()->create(['order_number' => 'TEST-LOG-2']);

        OrderStatusChanged::dispatch($order, OrderStatus::Pending, OrderStatus::Confirmed);

        $entry = $this->assertSingleEntry('commerce', LogLevel::Info, 'pending → confirmed');
        $this->assertSame('pending', $entry->context['from']);
        $this->assertSame('confirmed', $entry->context['to']);
    }

    // ─────────────────────────────────────────────────────────────────
    // Shipping
    // ─────────────────────────────────────────────────────────────────

    #[Test]
    public function shipment_created_routes_to_shipping_channel_at_info(): void
    {
        $shipment = $this->makeShipment();

        ShipmentCreated::dispatch($shipment);

        $entry = $this->assertSingleEntry('shipping', LogLevel::Info, 'Shipment created');
        $this->assertSame($shipment->id, $entry->context['shipment_id']);
    }

    #[Test]
    public function shipment_ready_dispatched_delivered_route_to_shipping_at_info(): void
    {
        $shipment = $this->makeShipment([
            'status' => ShipmentStatus::InTransit,
            'tracking_number' => 'TRK-123',
            'carrier' => 'dhl',
            'tracking_url' => 'https://t.example.test/TRK-123',
        ]);

        ShipmentReady::dispatch($shipment);
        ShipmentDispatched::dispatch($shipment);
        ShipmentDelivered::dispatch($shipment);

        $entries = $this->recorder()->entriesForChannel('shipping');
        $this->assertCount(3, $entries);
        foreach ($entries as $entry) {
            $this->assertSame(LogLevel::Info, $entry->level);
            $this->assertSame($shipment->id, $entry->context['shipment_id']);
        }

        $dispatched = collect($entries)->first(
            fn (LogEntry $e) => str_contains($e->message, 'dispatched'),
        );
        $this->assertNotNull($dispatched);
        $this->assertSame('TRK-123', $dispatched->context['tracking_number']);
    }

    #[Test]
    public function shipment_returned_to_sender_routes_to_shipping_at_warning_with_reason(): void
    {
        $shipment = $this->makeShipment();

        ShipmentReturnedToSender::dispatch($shipment, 'recipient absent');

        $entry = $this->assertSingleEntry('shipping', LogLevel::Warning, 'returned to sender');
        $this->assertSame('recipient absent', $entry->context['reason']);
    }

    #[Test]
    public function shipment_lost_routes_to_shipping_at_error_with_reason(): void
    {
        $shipment = $this->makeShipment();

        ShipmentLost::dispatch($shipment, 'carrier scan stopped');

        $entry = $this->assertSingleEntry('shipping', LogLevel::Error, 'marked lost');
        $this->assertSame('carrier scan stopped', $entry->context['reason']);
    }

    private function makeShipment(array $attrs = []): Shipment
    {
        return Shipment::factory()->create(array_merge([
            'shippable_type' => 'order',
            'shippable_id' => 1,
            'method' => 'standard',
            'status' => ShipmentStatus::Pending,
        ], $attrs));
    }

    // ─────────────────────────────────────────────────────────────────
    // FlowChain
    // ─────────────────────────────────────────────────────────────────

    #[Test]
    public function flowchain_started_routes_to_flowchain_at_info(): void
    {
        FlowChainStarted::dispatch('checkout', new LogTestPayload);

        $entry = $this->assertSingleEntry('flowchain', LogLevel::Info, 'FlowChain started: checkout');
        $this->assertSame('checkout', $entry->context['flow']);
    }

    #[Test]
    public function flowchain_completed_routes_to_flowchain_at_info(): void
    {
        $result = new FlowChainResult(
            status: FlowChainStatus::Completed,
            payload: new LogTestPayload,
            steps: [],
            durationMs: 12.5,
        );

        FlowChainCompleted::dispatch('checkout', $result);

        $entry = $this->assertSingleEntry('flowchain', LogLevel::Info, 'FlowChain completed: checkout');
        $this->assertSame('completed', $entry->context['status']);
        $this->assertSame(12.5, $entry->context['duration_ms']);
    }

    #[Test]
    public function flowchain_failed_routes_to_flowchain_at_error(): void
    {
        $result = new FlowChainResult(
            status: FlowChainStatus::Failed,
            payload: new LogTestPayload,
            steps: [],
            failedStep: 'InitiatePayment',
            exception: new RuntimeException('gateway down'),
            durationMs: 7.0,
        );

        FlowChainFailed::dispatch('checkout', $result);

        $entry = $this->assertSingleEntry('flowchain', LogLevel::Error, 'FlowChain failed: checkout');
        $this->assertSame('InitiatePayment', $entry->context['failed_step']);
        $this->assertSame('gateway down', $entry->context['exception']);
    }

    #[Test]
    public function flowchain_step_failed_routes_to_flowchain_at_warning(): void
    {
        FlowChainStepFailed::dispatch('checkout', 'InitiatePayment', new RuntimeException('declined'));

        $entry = $this->assertSingleEntry('flowchain', LogLevel::Warning, '');
        $this->assertSame('checkout', $entry->context['flow']);
    }

    // ─────────────────────────────────────────────────────────────────
    // Agent
    // ─────────────────────────────────────────────────────────────────

    #[Test]
    public function tool_invoked_routes_to_agent_channel_at_info(): void
    {
        $invocation = new ToolInvocation(
            tool: 'ping',
            redactedInput: ['ok' => true],
            output: ['pong' => true],
            error: null,
            durationMs: 1.234,
            bearerHash: 'abcdef012345',
        );

        ToolInvoked::dispatch($invocation);

        $entry = $this->assertSingleEntry('agent', LogLevel::Info, 'Tool ping invoked');
        $this->assertSame('ping', $entry->context['tool']);
        $this->assertSame(['ok' => true], $entry->context['input']);
        $this->assertSame('abcdef012345', $entry->context['bearer_hash']);
    }

    #[Test]
    public function tool_invocation_failed_routes_to_agent_channel_at_error(): void
    {
        $invocation = new ToolInvocation(
            tool: 'adjust_stock',
            redactedInput: ['delta' => 0],
            output: null,
            error: 'Delta must be >= 1',
            durationMs: 0.5,
            bearerHash: null,
        );

        ToolInvocationFailed::dispatch($invocation);

        $entry = $this->assertSingleEntry('agent', LogLevel::Error, 'Tool adjust_stock failed');
        $this->assertSame('Delta must be >= 1', $entry->context['error']);
    }

    #[Test]
    public function dynamic_client_registered_routes_to_agent_channel_at_notice(): void
    {
        DynamicClientRegistered::dispatch(
            'client-id-1',
            'Claude Code CLI',
            ['https://callback.example.test/cb'],
            true,
        );

        $entry = $this->assertSingleEntry('agent', LogLevel::Notice, 'Dynamic client registered: Claude Code CLI');
        $this->assertSame('client-id-1', $entry->context['client_id']);
        $this->assertTrue($entry->context['confidential']);
    }
}

final class LogTestPayload implements FlowPayload {}
