<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stock set aside for a reason — "Markt 15 Nov", "Kept for Ana" — so it
     * cannot sell online (stock-holds brief §4.1). A hold has no lines table:
     * its lines are the Pending reservations that reference it, so what it
     * holds is derived at read time and there is no counter to drift. It never
     * expires and ends only by its Release button (`released_at`).
     */
    public function up(): void
    {
        Schema::create('stock_holds', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->date('held_for')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_holds');
    }
};
