<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The supplier's invoice and our payment of it (bookkeeping brief §12 P-4).
     * The invoice, not the order, is the Beleg: the order's `total` is net of
     * input VAT and the VAT on shipping is recorded nowhere, so the invoice
     * carries its own gross total and per-rate summary (`{rate_bps,
     * taxable_base, tax}`, the shape orders use). `paid_at` is the start of the
     * day the admin says it was paid, in the zone the admin shows.
     */
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->string('supplier_invoice_number')->nullable()->after('total');
            $table->date('supplier_invoice_date')->nullable()->after('supplier_invoice_number');
            $table->integer('supplier_invoice_total')->nullable()->after('supplier_invoice_date');
            $table->json('supplier_invoice_tax_summary')->nullable()->after('supplier_invoice_total');
            $table->timestamp('paid_at')->nullable()->after('supplier_invoice_tax_summary');
            $table->integer('amount_paid')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'supplier_invoice_number',
                'supplier_invoice_date',
                'supplier_invoice_total',
                'supplier_invoice_tax_summary',
                'paid_at',
                'amount_paid',
            ]);
        });
    }
};
