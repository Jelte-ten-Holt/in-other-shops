<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.76.0: whether an attached row appears in its host's own list of the
 * collection (a resource page's download panel, say), or only where the host's
 * text places it. Default true, so every existing row keeps showing where it
 * shows today. Only a collection configured with `listed_toggle => true`
 * offers the switch; `MediaSchema` saves every other row as listed.
 *
 * Additive `ALTER TABLE … ADD` only — one statement, no rebuild, no FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mediables', function (Blueprint $table): void {
            $table->boolean('is_listed')->default(true)->after('is_cover');
        });
    }

    public function down(): void
    {
        Schema::table('mediables', function (Blueprint $table): void {
            $table->dropColumn('is_listed');
        });
    }
};
