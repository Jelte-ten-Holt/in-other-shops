<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->integer('tax_rate_bps')->nullable()->after('tax');
            $table->string('tax_rate_country_code', 2)->nullable()->after('tax_rate_bps');
            $table->string('shipping_method_identifier')->nullable()->after('tax_rate_country_code');
            $table->integer('shipping_cost')->default(0)->after('shipping_method_identifier');
            $table->string('shipping_cost_currency', 3)->nullable()->after('shipping_cost');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'tax_rate_bps',
                'tax_rate_country_code',
                'shipping_method_identifier',
                'shipping_cost',
                'shipping_cost_currency',
            ]);
        });
    }
};
