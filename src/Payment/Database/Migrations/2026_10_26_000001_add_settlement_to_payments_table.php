<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a payment did to the shop's balance at the gateway, for the
     * bookkeeping app's read API (bookkeeping brief §12 P-2): the charge and
     * the gateway's balance transaction, the fee, the net, and the amount in
     * the settlement currency with the rate when it was converted. `paid_at`
     * is when the money reached the gateway balance, written with the rest.
     * Amounts are minor units of `settled_currency`; the rate is a decimal so
     * it reads back as the same string on every driver.
     *
     * All nullable: a payment is settled some time after it succeeds, and a
     * gateway without settlements (a manual payment) never fills them.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('charge_reference')->nullable()->after('gateway_reference');
            $table->string('settlement_reference')->nullable()->after('charge_reference');
            $table->timestamp('paid_at')->nullable()->after('settlement_reference');
            $table->integer('fee')->nullable()->after('paid_at');
            $table->integer('net')->nullable()->after('fee');
            $table->integer('settled_amount')->nullable()->after('net');
            $table->string('settled_currency', 3)->nullable()->after('settled_amount');
            $table->decimal('exchange_rate', 16, 10)->nullable()->after('settled_currency');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn([
                'charge_reference',
                'settlement_reference',
                'paid_at',
                'fee',
                'net',
                'settled_amount',
                'settled_currency',
                'exchange_rate',
            ]);
        });
    }
};
