<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Purchasing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InOtherShops\Purchasing\Actions\CreatePurchaseOrder;
use InOtherShops\Purchasing\Actions\PlacePurchaseOrder;
use InOtherShops\Purchasing\Actions\ReceiveItems;
use InOtherShops\Purchasing\Actions\ReconcilePurchaseReceipts;
use InOtherShops\Purchasing\Models\PurchaseOrder;
use InOtherShops\Purchasing\Models\Supplier;
use InOtherShops\Tests\Stubs\TestPurchasable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The read-only purchasing tripwire (G11): a purchase-order line's
 * `quantity_received` counter must equal the sum of its `Received` movement
 * ledger. Detection only — it reports drift, it does not repair it.
 */
final class ReconcilePurchaseReceiptsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: PurchaseOrder, 1: int}
     */
    private function placedOrderWithReceipt(int $ordered = 10, int $received = 6): array
    {
        $order = app(CreatePurchaseOrder::class)(
            supplier: Supplier::factory()->create(),
            lines: [['purchasable' => TestPurchasable::factory()->create(), 'quantity_ordered' => $ordered, 'unit_cost' => 500]],
        );
        app(PlacePurchaseOrder::class)($order);
        $lineId = $order->lines()->first()->id;
        app(ReceiveItems::class)($order->refresh(), [$lineId => $received]);

        return [$order, $lineId];
    }

    #[Test]
    public function a_received_order_reconciles_clean(): void
    {
        $this->placedOrderWithReceipt(10, 6);

        $report = app(ReconcilePurchaseReceipts::class)();

        $this->assertTrue($report->isClean());
    }

    #[Test]
    public function it_flags_a_quantity_received_that_diverges_from_the_ledger(): void
    {
        [, $lineId] = $this->placedOrderWithReceipt(10, 6);

        // Corrupt the counter directly, leaving the Received movement (qty 6) intact.
        DB::table('purchase_order_lines')->where('id', $lineId)->update(['quantity_received' => 9]);

        $report = app(ReconcilePurchaseReceipts::class)();

        $this->assertFalse($report->isClean());
        $this->assertCount(1, $report->mismatches);
        $this->assertSame($lineId, $report->mismatches[0]['line_id']);
        $this->assertSame(9, $report->mismatches[0]['recorded']);
        $this->assertSame(6, $report->mismatches[0]['ledger']);
    }

    #[Test]
    public function the_command_exits_non_zero_on_drift_and_zero_when_clean(): void
    {
        [, $lineId] = $this->placedOrderWithReceipt(10, 6);

        $this->artisan('purchasing:reconcile-receipts')->assertExitCode(0);

        DB::table('purchase_order_lines')->where('id', $lineId)->update(['quantity_received' => 2]);

        $this->artisan('purchasing:reconcile-receipts')->assertExitCode(1);
    }
}
