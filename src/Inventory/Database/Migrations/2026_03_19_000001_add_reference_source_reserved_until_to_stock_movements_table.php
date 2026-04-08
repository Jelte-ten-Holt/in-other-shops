<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->nullableMorphs('reference');
            $table->string('source')->nullable();
            $table->timestamp('reserved_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropMorphs('reference');
            $table->dropColumn(['source', 'reserved_until']);
        });
    }
};
