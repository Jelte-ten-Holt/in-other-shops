<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Decision D4 (2026-07-02): shipment history must survive order-line deletion.
 * The create migration shipped order_line_id with cascadeOnDelete; this forward
 * migration converges every database to restrictOnDelete. Deleting an order
 * line that has shipment items is now blocked — delete the shipment first if
 * that is really intended.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->swapForeignKey(cascade: false);
    }

    public function down(): void
    {
        $this->swapForeignKey(cascade: true);
    }

    private function swapForeignKey(bool $cascade): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            // SQLite cannot drop foreign keys. Every SQLite environment here
            // migrates fresh (package test suite, throwaway dev DBs) and the
            // table predates launch, so rebuilding it empty is safe.
            Schema::dropIfExists('shipment_items');
            Schema::create('shipment_items', function (Blueprint $table) use ($cascade) {
                $table->id();
                $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
                $fk = $table->foreignId('order_line_id')->constrained('order_lines');
                $cascade ? $fk->cascadeOnDelete() : $fk->restrictOnDelete();
                $table->integer('quantity');
                $table->timestamps();

                $table->unique(['shipment_id', 'order_line_id']);
            });

            return;
        }

        Schema::table('shipment_items', function (Blueprint $table) use ($cascade) {
            $table->dropForeign(['order_line_id']);
            $fk = $table->foreign('order_line_id')->references('id')->on('order_lines');
            $cascade ? $fk->cascadeOnDelete() : $fk->restrictOnDelete();
        });
    }
};
