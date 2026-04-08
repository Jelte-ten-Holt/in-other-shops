<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prices', function (Blueprint $table) {
            $table->id();
            $table->morphs('priceable');
            $table->foreignId('price_list_id')->nullable()->constrained('price_lists')->nullOnDelete();
            $table->string('currency', 3);
            $table->integer('amount');
            $table->integer('compare_at_amount')->nullable();
            $table->unsignedInteger('minimum_quantity')->default(1);
            $table->timestamps();

            $table->unique(
                ['priceable_type', 'priceable_id', 'price_list_id', 'currency', 'minimum_quantity'],
                'prices_priceable_list_currency_quantity_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prices');
    }
};
