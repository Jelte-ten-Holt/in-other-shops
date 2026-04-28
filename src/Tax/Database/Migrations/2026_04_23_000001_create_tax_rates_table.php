<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->string('country_code', 2)->index();
            $table->string('tax_category')->nullable()->index();
            $table->integer('rate_bps');
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['country_code', 'tax_category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
    }
};
