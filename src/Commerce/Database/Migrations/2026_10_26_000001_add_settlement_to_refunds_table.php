<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a refund did to the shop's balance at the gateway (bookkeeping
     * brief §12 P-2): the balance transaction, any fee, and the amount in the
     * settlement currency with its rate. A refund of a USD sale settles at the
     * refund day's rate, not the sale's, so it carries its own. Nullable for
     * the same reasons as the payment's.
     */
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            $table->string('settlement_reference')->nullable()->after('gateway_refund_id');
            $table->integer('fee')->nullable()->after('tax_summary');
            $table->integer('settled_amount')->nullable()->after('fee');
            $table->string('settled_currency', 3)->nullable()->after('settled_amount');
            $table->decimal('exchange_rate', 16, 10)->nullable()->after('settled_currency');
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            $table->dropColumn(['settlement_reference', 'fee', 'settled_amount', 'settled_currency', 'exchange_rate']);
        });
    }
};
