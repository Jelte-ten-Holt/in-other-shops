<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Forward, additive status index for payments (T-B-MIG1-REVISE).
 *
 * The create-table migration ships no index on `status`, yet webhook/refund
 * lookups filter on it. This is index-only DDL: it applies to every existing
 * consumer through a normal `migrate` (no `migrate:fresh`, no data loss) and is
 * safe online. Length standardisation was deliberately dropped — `payments.status`
 * is already `varchar(255)`, so there is nothing to widen and narrowing would be
 * a pure-cosmetic table rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex('payments_status_index');
        });
    }
};
