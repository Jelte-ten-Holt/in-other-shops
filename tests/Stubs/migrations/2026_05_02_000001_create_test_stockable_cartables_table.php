<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_stockable_cartables', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('tracks_stock')->default(true);
            $table->boolean('allow_backorder')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_stockable_cartables');
    }
};
