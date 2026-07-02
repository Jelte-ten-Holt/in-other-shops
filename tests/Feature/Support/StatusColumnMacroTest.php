<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * T-B-MIG1 — the `status` Blueprint macro standardises every status column at
 * `string(30)` with one index policy. The length widening is a MySQL/Postgres
 * concern (the suite's SQLite ignores varchar length, so it can't be asserted
 * here — it's covered by the migrations running clean); the index policy is
 * observable and pinned below.
 */
final class StatusColumnMacroTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_macro_indexes_status_on_every_status_table(): void
    {
        // orders/payments/shipments previously carried mixed/no indexes; the
        // macro gives each a single-column status index by default.
        foreach (['orders', 'payments', 'shipments', 'purchase_orders'] as $table) {
            $this->assertTrue(
                $this->hasIndex($table, "{$table}_status_index"),
                "{$table} must carry the macro's single-column status index.",
            );
        }
    }

    #[Test]
    public function stock_reservations_opts_out_of_the_single_index_and_keeps_its_composite(): void
    {
        // Passing index: false avoids a redundant single index alongside the
        // composite that the expiry query relies on.
        $this->assertFalse(
            $this->hasIndex('stock_reservations', 'stock_reservations_status_index'),
            'stock_reservations opted out of the single status index (index: false).',
        );
        $this->assertTrue(
            $this->hasIndex('stock_reservations', 'stock_reservations_status_reserved_until_index'),
            'stock_reservations must keep its composite (status, reserved_until) index.',
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
