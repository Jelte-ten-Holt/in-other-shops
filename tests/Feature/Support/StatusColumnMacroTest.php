<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The `$table->status()` macro is the go-forward convention for new status
 * columns (T-B-MIG1). It is NOT retrofitted onto the existing five status
 * tables — T-B-MIG1-REVISE reverted that, because editing a shipped create
 * migration in place never re-runs for a consumer that already migrated.
 *
 * So this test exercises the macro **directly** on throwaway tables (both index
 * policies), rather than asserting the domain tables — whose indexes come from
 * their own create migrations (`orders`, `purchase_orders`) or from the forward
 * index migration (`payments`, `shipments`), not from the macro. Column length
 * (`string(30)`) is a MySQL/Postgres concern the suite's SQLite ignores, so only
 * the observable index policy is asserted.
 */
final class StatusColumnMacroTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_macro_creates_a_status_column_with_a_single_index_by_default(): void
    {
        Schema::create('status_macro_probe', function (Blueprint $table): void {
            $table->id();
            $table->status();
        });

        $this->assertTrue(Schema::hasColumn('status_macro_probe', 'status'));
        $this->assertTrue(
            $this->hasIndex('status_macro_probe', 'status_macro_probe_status_index'),
            'the default macro must add a single-column status index.',
        );

        Schema::dropIfExists('status_macro_probe');
    }

    #[Test]
    public function the_macro_omits_the_index_when_index_false_is_passed(): void
    {
        Schema::create('status_macro_probe_no_index', function (Blueprint $table): void {
            $table->id();
            $table->status(index: false);
        });

        $this->assertTrue(Schema::hasColumn('status_macro_probe_no_index', 'status'));
        $this->assertFalse(
            $this->hasIndex('status_macro_probe_no_index', 'status_macro_probe_no_index_status_index'),
            'status(index: false) must NOT add the single-column index (caller carries its own).',
        );

        Schema::dropIfExists('status_macro_probe_no_index');
    }

    #[Test]
    public function the_forward_migration_indexes_payments_and_shipments_status(): void
    {
        // payments/shipments create-table migrations ship no status index; the
        // additive T-B-MIG1-REVISE forward migration backfills one on each.
        $this->assertTrue(
            $this->hasIndex('payments', 'payments_status_index'),
            'the forward migration must index payments.status.',
        );
        $this->assertTrue(
            $this->hasIndex('shipments', 'shipments_status_index'),
            'the forward migration must index shipments.status.',
        );
    }

    #[Test]
    public function stock_reservations_keeps_its_composite_status_index_and_no_single_index(): void
    {
        // The reverted create migration defines status as a plain string and
        // relies on the composite (status, reserved_until) index the expiry
        // query walks — it must not also carry a redundant single status index.
        $this->assertTrue(
            $this->hasIndex('stock_reservations', 'stock_reservations_status_reserved_until_index'),
            'stock_reservations must keep its composite (status, reserved_until) index.',
        );
        $this->assertFalse(
            $this->hasIndex('stock_reservations', 'stock_reservations_status_index'),
            'stock_reservations must not carry a redundant single status index.',
        );
    }

    private function hasIndex(string $table, string $index): bool
    {
        return DB::selectOne(
            "select name from sqlite_master where type = 'index' and tbl_name = ? and name = ?",
            [$table, $index],
        ) !== null;
    }
}
