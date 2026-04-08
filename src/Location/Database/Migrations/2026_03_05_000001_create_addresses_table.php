<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->morphs('addressable');
            $table->string('type', 20);
            $table->string('first_name');
            $table->string('last_name');
            $table->string('line_1');
            $table->string('line_2')->nullable();
            $table->string('city');
            $table->string('state')->nullable();
            $table->string('postal_code');
            $table->string('country_code', 2);
            $table->string('phone')->nullable();
            $table->timestamps();

            $table->index(['addressable_type', 'addressable_id', 'type'], 'addresses_addressable_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
