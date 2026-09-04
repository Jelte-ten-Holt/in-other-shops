<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Media pipeline Phase 2 (v0.69.0): intrinsic dimensions and the variant map.
 *
 * `width`/`height` are EXIF-orientation-corrected pixel dimensions, filled by
 * the model's `saving` hook from the file header (no decode). `variants` is
 * the ladder the queued job wrote: `null` = never attempted, `{}` = attempted
 * and nothing produced (reason logged), otherwise `{"400": {path,width,height}, …}`.
 *
 * Additive `ALTER TABLE … ADD` only — no rebuild, no FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->unsignedInteger('width')->nullable()->after('size');
            $table->unsignedInteger('height')->nullable()->after('width');
            $table->json('variants')->nullable()->after('height');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->dropColumn(['width', 'height', 'variants']);
        });
    }
};
