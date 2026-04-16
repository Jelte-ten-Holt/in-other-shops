<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->morphs('cartable');
            $table->unsignedInteger('quantity')->default(1);
            // Snapshot of unit price (smallest currency subunit) at add time.
            // Lets the UI surface "price has changed since you added this"
            // without recomputing on every render. Nullable because not every
            // cartable has a resolvable price (gift items, backorder, etc.).
            $table->unsignedInteger('unit_price')->nullable();
            $table->string('currency', 3)->nullable();
            $table->timestamps();

            $table->unique(['cart_id', 'cartable_type', 'cartable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
