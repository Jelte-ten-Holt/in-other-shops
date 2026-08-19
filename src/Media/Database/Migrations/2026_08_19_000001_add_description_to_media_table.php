<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Forward, additive caption column for media.
 *
 * `alt` and `description` are not the same field and neither substitutes for the
 * other: `alt` is the accessibility text a screen reader announces in place of
 * the image, `description` is prose rendered visibly beneath it. A caption is
 * unbounded prose, so it is `text` rather than the `string` used for `alt`.
 *
 * Nullable and additive: applies to existing consumers via a normal `migrate`,
 * no rewrite of existing rows, no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('alt');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->dropColumn('description');
        });
    }
};
