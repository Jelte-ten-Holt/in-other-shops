<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Logging;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InOtherShops\Inventory\Actions\AdjustStock;
use InOtherShops\Inventory\Enums\StockMovementReason;
use InOtherShops\Logging\Handlers\DatabaseLogHandler;
use InOtherShops\Purchasing\Actions\CreatePurchaseOrder;
use InOtherShops\Purchasing\Actions\PlacePurchaseOrder;
use InOtherShops\Purchasing\Actions\ReceiveItems;
use InOtherShops\Purchasing\Models\Supplier;
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
 * Pattern to extend (remaining channels — commerce/payment/shipping/flowchain/
 * agent — tracked under G3 in the round-2 audit): route the channel in
 * {@see defineEnvironment}, run the real action, assert the row.
 */
final class AuditPipelineRowTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Must be set before the domain providers boot — subscribers capture the
        // LogDispatcher (and thus its handler map) at subscribe time.
        foreach (['purchasing', 'inventory'] as $channel) {
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
}
