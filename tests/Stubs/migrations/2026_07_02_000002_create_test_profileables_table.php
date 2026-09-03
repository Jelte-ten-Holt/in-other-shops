<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fixture table for InitiatePaymentTest's TestProfileable (HasPaymentProfiles).
 *
 * It lives here rather than in that test's defineDatabaseMigrations() because a
 * table created there is not part of the migration set, and RefreshDatabase's
 * migrate:fresh drops every table it finds — including this one — without ever
 * putting it back. On in-memory SQLite that is invisible (each test gets a new
 * database, so the create always re-runs); on MySQL, whose one database is
 * shared by the whole process, it surfaced as this table both "already exists"
 * and "doesn't exist", depending on where migrate:fresh fell in the random test
 * order. Being a migration makes it recreate along with everything else.
 *
 * The model itself stays in the test file — this is a payment-only fixture, and
 * the shared stub set has no business absorbing it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_profileables', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_profileables');
    }
};
