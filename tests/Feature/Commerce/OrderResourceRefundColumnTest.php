<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce;

use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Support\Contracts\TranslatableContentDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InOtherShops\Commerce\Filament\Resources\OrderResource;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Commerce\Order\Models\Refund;
use InOtherShops\Tests\Support\BootsFilament;
use InOtherShops\Tests\TestCase;
use Livewire\Component;
use PHPUnit\Framework\Attributes\Test;

/**
 * S3 of the 2026-09-07 complexity audit: the orders list — the most-visited
 * page in the admin — fired one `SUM(refunds.amount)` per row from the refund
 * badge's state callback. At 25–100 rows a page that is 25–100 extra queries
 * for a column that is empty on almost every row.
 *
 * The fix is one page-wide `withSum` in `modifyQueryUsing`, with the column
 * null-coalescing to the per-row sum. The coalesce is not belt-and-braces: a
 * consumer subclassing `OrderResource` and overriding `table()` loses the
 * aggregate, and without the fallback every order would read as unrefunded —
 * a wrong answer is worse than a slow one.
 */
final class OrderResourceRefundColumnTest extends TestCase
{
    use BootsFilament;
    use RefreshDatabase;

    #[Test]
    public function the_refund_column_fires_no_query_per_row(): void
    {
        $refunded = Order::factory()->create(['total' => 10000]);
        Refund::factory()->for($refunded, 'order')->create(['amount' => 10000]);

        $partial = Order::factory()->create(['total' => 10000]);
        Refund::factory()->for($partial, 'order')->create(['amount' => 2500]);

        Order::factory()->count(3)->create(['total' => 10000]);

        $table = $this->ordersTable();

        $records = $table->getQuery()->get();
        $this->assertCount(5, $records);

        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();

        $states = $records->map(fn (Order $order): ?string => $this->refundState($table, $order))->all();

        $queries = DB::connection()->getQueryLog();
        DB::connection()->disableQueryLog();

        $this->assertSame(
            [],
            $queries,
            'Reading the refund column must issue no query — the page-wide withSum already answered it. Got '
            .count($queries).'.',
        );

        $this->assertSame(
            [__('shops-commerce::orders.refund_state.refunded'), __('shops-commerce::orders.refund_state.partial'), null, null, null],
            $states,
        );
    }

    #[Test]
    public function the_column_falls_back_to_the_per_row_sum_when_the_aggregate_is_absent(): void
    {
        $order = Order::factory()->create(['total' => 10000]);
        Refund::factory()->for($order, 'order')->create(['amount' => 2500]);

        $table = $this->ordersTable();

        // A consumer that overrides modifyQueryUsing hands the column a record
        // with no `refunds_sum_amount`. It must still say "partially refunded",
        // not "unrefunded".
        $bare = Order::query()->findOrFail($order->getKey());

        $this->assertNull($bare->getAttribute('refunds_sum_amount'));
        $this->assertSame(
            __('shops-commerce::orders.refund_state.partial'),
            $this->refundState($table, $bare),
        );
    }

    #[Test]
    public function the_table_query_carries_the_refund_aggregate(): void
    {
        $this->assertStringContainsString(
            'refunds_sum_amount',
            $this->ordersTable()->getQuery()->toSql(),
        );
    }

    private function ordersTable(): Table
    {
        return (new OrderTableHost)->buildTable();
    }

    private function refundState(Table $table, Order $order): ?string
    {
        $column = $table->getColumn('refund_state');
        $this->assertNotNull($column, 'OrderResource table must carry a refund_state column.');

        return $column->record($order)->getState();
    }
}

/**
 * The package suite has no Filament panel page for `OrderResource`, and a
 * `Table` needs a `HasTable` host. This is the smallest one that satisfies it.
 */
class OrderTableHost extends Component implements HasTable
{
    use InteractsWithTable;

    public function makeFilamentTranslatableContentDriver(): ?TranslatableContentDriver
    {
        return null;
    }

    public function buildTable(): Table
    {
        // The column's getState() reaches back through the column to its table
        // to its host, so the host has to actually hold the table it built.
        return $this->table = OrderResource::table(
            Table::make($this)->query(fn () => Order::query()),
        );
    }
}
