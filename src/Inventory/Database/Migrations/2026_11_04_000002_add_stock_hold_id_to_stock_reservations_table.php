<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string FOREIGN_KEY = 'stock_reservations_stock_hold_id_fk';

    /**
     * The hold an order's reservation was moved from (stock-holds brief D10),
     * so deleting or cancelling the order gives the units back to that hold
     * while it is open, instead of putting a market piece on sale online. Set
     * only on reservations an order took from a hold; a hold's own
     * reservations reference it through `reference_*`.
     *
     * Recovery-aware: MySQL DDL is not transactional, and adding the column
     * and its constraint are two statements, so a rerun after a half-applied
     * up() skips whatever already exists. The constraint is named explicitly
     * (MySQL constraint names are schema-global).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('stock_reservations', 'stock_hold_id')) {
            Schema::table('stock_reservations', function (Blueprint $table): void {
                $table->unsignedBigInteger('stock_hold_id')->nullable()->after('reference_id');
            });
        }

        if (! $this->hasForeignKey()) {
            Schema::table('stock_reservations', function (Blueprint $table): void {
                $table->foreign('stock_hold_id', self::FOREIGN_KEY)->references('id')->on('stock_holds')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if ($this->hasForeignKey()) {
            Schema::table('stock_reservations', function (Blueprint $table): void {
                $table->dropForeign(self::FOREIGN_KEY);
            });
        }

        if (Schema::hasColumn('stock_reservations', 'stock_hold_id')) {
            Schema::table('stock_reservations', function (Blueprint $table): void {
                $table->dropColumn('stock_hold_id');
            });
        }
    }

    private function hasForeignKey(): bool
    {
        foreach (Schema::getForeignKeys('stock_reservations') as $foreignKey) {
            if ($foreignKey['name'] === self::FOREIGN_KEY || $foreignKey['columns'] === ['stock_hold_id']) {
                return true;
            }
        }

        return false;
    }
};
