<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('profileable_type');
            $table->unsignedBigInteger('profileable_id');
            $table->string('gateway');
            $table->string('gateway_customer_id');
            $table->json('gateway_data')->nullable();
            $table->timestamps();

            $table->unique(['profileable_type', 'profileable_id', 'gateway']);
            $table->index(['profileable_type', 'profileable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_profiles');
    }
};
