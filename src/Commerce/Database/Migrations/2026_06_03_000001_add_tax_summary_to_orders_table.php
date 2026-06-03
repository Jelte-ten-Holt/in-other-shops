<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // Per-rate VAT breakdown: list of {rate_bps, taxable_base, tax}.
            // The invoice/VAT-return shape — VAT is summarised per rate, not
            // per line. Read via Order::taxSummary(); storage may move to a
            // table later without changing that accessor.
            $table->json('tax_summary')->nullable()->after('tax_rate_country_code');
        });

        Schema::table('order_lines', function (Blueprint $table): void {
            // Per-line tax amount is no longer tracked — tax lives in the order's
            // per-bracket summary. Nullable = "not tracked per line".
            $table->integer('tax_amount')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('tax_summary');
        });

        Schema::table('order_lines', function (Blueprint $table): void {
            $table->integer('tax_amount')->default(0)->change();
        });
    }
};
