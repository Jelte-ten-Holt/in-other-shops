<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('option_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('option_id')->constrained('options')->cascadeOnDelete();
            $table->string('value');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['option_id', 'value']);
            $table->index(['option_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('option_values');
    }
};
