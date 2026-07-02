<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('payable_type');
            $table->unsignedBigInteger('payable_id');
            $table->integer('amount');
            $table->integer('amount_refunded')->default(0);
            $table->string('currency', 3);
            $table->string('status')->default('pending');
            $table->string('gateway');
            $table->string('gateway_reference')->nullable();
            $table->json('gateway_data')->nullable();
            $table->timestamps();

            $table->index(['payable_type', 'payable_id']);
            // Gateway refs are only guaranteed unique within a gateway — composite
            // unique prevents cross-gateway false matches in webhook lookups.
            // Nullable gateway_reference rows are still allowed (MySQL treats NULLs
            // as distinct in unique indexes).
            $table->unique(['gateway', 'gateway_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
