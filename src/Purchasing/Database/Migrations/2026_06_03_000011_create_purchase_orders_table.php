<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->status()->default('draft');
            $table->string('currency', 3);
            $table->timestamp('ordered_at')->nullable();
            $table->date('expected_delivery_at')->nullable();
            $table->integer('shipping_cost')->default(0);
            $table->integer('customs_cost')->default(0);
            $table->integer('subtotal')->default(0);
            $table->integer('total')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
