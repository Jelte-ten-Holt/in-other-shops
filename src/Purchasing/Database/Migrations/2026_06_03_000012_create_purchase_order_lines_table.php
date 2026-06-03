<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->nullableMorphs('purchasable');
            $table->string('description');
            $table->string('sku')->nullable();
            $table->unsignedInteger('quantity_ordered')->default(1);
            $table->unsignedInteger('quantity_received')->default(0);
            $table->integer('unit_cost');
            $table->integer('input_vat')->nullable();
            $table->string('tax_category')->nullable();
            $table->integer('line_cost');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines');
    }
};
