<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the audit actor (F21): who a `domain_logs` row is attributed to. Dedicated
 * columns rather than a context-JSON field so the trail is queryable by actor
 * ("everything the agent touched", "rows where actor_label = 'unknown'"). All
 * nullable — pre-existing rows pre-date attribution and read as unknown; no
 * backfill. No index yet (scale is low — add when a query needs it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domain_logs', function (Blueprint $table): void {
            $table->string('actor_type', 20)->nullable()->after('context');
            $table->string('actor_id')->nullable()->after('actor_type');
            $table->string('actor_label')->nullable()->after('actor_id');
        });
    }

    public function down(): void
    {
        Schema::table('domain_logs', function (Blueprint $table): void {
            $table->dropColumn(['actor_type', 'actor_id', 'actor_label']);
        });
    }
};
