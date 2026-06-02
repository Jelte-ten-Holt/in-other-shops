<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('optionables', function (Blueprint $table) {
            $table->foreignId('option_id')->constrained('options')->cascadeOnDelete();
            $table->morphs('optionable');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['option_id', 'optionable_type', 'optionable_id'], 'optionables_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('optionables');
    }
};
