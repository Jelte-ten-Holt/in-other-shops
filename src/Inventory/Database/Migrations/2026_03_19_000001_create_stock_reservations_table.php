<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reserve_movement_id')->constrained('stock_movements')->cascadeOnDelete();
            $table->foreignId('release_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('status');
            $table->timestamp('reserved_until')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->nullableMorphs('reference');
            $table->string('description')->nullable();
            $table->string('source')->nullable();
            $table->timestamps();

            $table->unique('reserve_movement_id');
            $table->index(['status', 'reserved_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
    }
};
