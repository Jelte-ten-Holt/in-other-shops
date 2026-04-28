<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->string('tax_category')->default('physical_goods')->after('line_total');
            $table->integer('tax_rate_bps')->nullable()->after('tax_category');
            $table->integer('tax_amount')->default(0)->after('tax_rate_bps');
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->dropColumn(['tax_category', 'tax_rate_bps', 'tax_amount']);
        });
    }
};
