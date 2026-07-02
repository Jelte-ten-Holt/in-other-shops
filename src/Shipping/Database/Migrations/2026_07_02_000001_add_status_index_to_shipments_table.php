<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Forward, additive status index for shipments (T-B-MIG1-REVISE).
 *
 * The create-table migration ships no index on `status`, yet shipment listings
 * filter on it. Index-only DDL: applies to existing consumers via normal
 * `migrate` (no fresh, no data loss), safe online. Length standardisation was
 * dropped — `shipments.status` is already `varchar(255)`; narrowing would be a
 * cosmetic table rewrite with no functional gain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropIndex('shipments_status_index');
        });
    }
};
