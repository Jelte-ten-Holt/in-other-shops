<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_stockable_localizables', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->foreignId('locale_group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('locale', 10)->nullable();
            $table->timestamps();

            $table->unique(['locale_group_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_stockable_localizables');
    }
};
