<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_shippable_cartables', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('requires_shipping')->default(true);
            $table->string('tax_category')->default('physical_goods');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_shippable_cartables');
    }
};
