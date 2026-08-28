<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⚠ SAME-BASENAME ADOPTION — do not rename this file, and do not "tidy" the
 * schema below.
 *
 * in-other-worlds created this table from a project migration with THIS EXACT
 * basename. The migrator keys applied migrations by basename across every load
 * path, so IOW's existing `migrations` row matches this file and the migration
 * is skipped there — its live table and data are untouched — while a fresh
 * install (bianka) runs it for real. Renaming the file would re-run it against
 * IOW's populated table.
 *
 * The body is a VERBATIM copy of IOW's migration and must stay that way, so the
 * table a fresh install gets is the table IOW already has: column order,
 * `created_at` as a nullable `timestamp` with no `updated_at`, and FK/unique/
 * index names left as Laravel's derived defaults — a deliberate exception to
 * the package's explicit-FK-names convention, because parity with the live
 * table wins here.
 *
 * Measured on MySQL 8.0.46 rather than assumed (the build plan predicted
 * otherwise): this produces THREE keys — PRIMARY, `{table}_{fk}_unique`, and
 * `{table}_source_type_source_id_index`. There is no separate `*_foreign`
 * backing index, because InnoDB reuses the UNIQUE index on the FK column for
 * that, and the order in which `constrained()` and `unique()` are declared
 * makes no difference to the result. Do not reorder them chasing a fourth key
 * that this schema does not produce.
 *
 * Consequence worth knowing: `down()` now targets IOW's live table too, so a
 * `migrate:rollback` walking back this far drops real attribution history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_item_attributions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cart_item_id')->constrained()->cascadeOnDelete();
            $table->nullableMorphs('source');
            $table->timestamp('created_at')->nullable();

            // One attribution per cart_item — first-source-wins semantics.
            // SnapshotCartItemAttributions reads back keyed by cart_item_id.
            $table->unique('cart_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_item_attributions');
    }
};
