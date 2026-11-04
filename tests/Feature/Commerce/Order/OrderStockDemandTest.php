<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Order;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Commerce\Order\Support\OrderStockDemand;
use InOtherShops\Tests\Stubs\TestStockableOrderable;
use InOtherShops\Tests\Stubs\TestStockBundle;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The arithmetic the order page shows and ReserveOrderStock re-checks
 * (stock-holds brief §4.2, §4.3).
 */
final class OrderStockDemandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function bundles_are_expanded_first_and_the_sequence_is_in_lock_order_whatever_the_line_order(): void
    {
        $bowl = TestStockableOrderable::factory()->create();
        $cup = TestStockableOrderable::factory()->create();
        $plate = TestStockableOrderable::factory()->create();
        $set = TestStockBundle::factory()->create(['components' => [
            ['type' => 'test_stockable_orderable', 'id' => $plate->id, 'quantity' => 2],
            ['type' => 'test_stockable_orderable', 'id' => $bowl->id, 'quantity' => 1],
        ]]);

        $entries = OrderStockDemand::fromLines([
            ['orderable' => $cup, 'quantity' => 1, 'line' => 0],
            ['orderable' => $set, 'quantity' => 3, 'line' => 1],
            ['orderable' => $bowl, 'quantity' => 1, 'line' => 2],
        ]);

        $this->assertSame(
            [[$bowl->id, 3, 1], [$bowl->id, 1, 2], [$cup->id, 1, 0], [$plate->id, 6, 1]],
            array_map(fn (array $e): array => [$e['stockable']->getKey(), $e['quantity'], $e['line']], $entries),
        );
    }

    #[Test]
    public function the_hold_is_consumed_in_entry_order_and_the_rest_comes_from_stock(): void
    {
        $bowl = TestStockableOrderable::factory()->create();
        $entries = OrderStockDemand::fromLines([
            ['orderable' => $bowl, 'quantity' => 2, 'line' => 0],
            ['orderable' => $bowl, 'quantity' => 2, 'line' => 1],
        ]);
        $group = $entries[0]['group'];

        $split = OrderStockDemand::split($entries, [$group => 3]);

        $this->assertSame([[2, 0], [1, 1]], array_map(fn (array $e): array => [$e['fromHold'], $e['fromStock']], $split));
        $this->assertSame([$group => 3], OrderStockDemand::fromHoldByGroup($split));
    }

    #[Test]
    public function lines_without_an_item_or_quantity_and_untracked_items_take_nothing(): void
    {
        $download = TestStockableOrderable::factory()->untracked()->create();
        $bowl = TestStockableOrderable::factory()->create();

        $entries = OrderStockDemand::fromLines([
            ['orderable' => null, 'quantity' => 1, 'line' => 0],
            ['orderable' => $download, 'quantity' => 1, 'line' => 1],
            ['orderable' => $bowl, 'quantity' => 0, 'line' => 2],
        ]);

        $this->assertSame([], $entries);
    }
}
