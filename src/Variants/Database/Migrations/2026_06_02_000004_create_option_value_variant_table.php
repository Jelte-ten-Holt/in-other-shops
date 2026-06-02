<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('option_value_variant', function (Blueprint $table) {
            $table->foreignId('variant_id')->constrained('variants')->cascadeOnDelete();
            $table->foreignId('option_value_id')->constrained('option_values')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['variant_id', 'option_value_id'], 'option_value_variant_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('option_value_variant');
    }
};
