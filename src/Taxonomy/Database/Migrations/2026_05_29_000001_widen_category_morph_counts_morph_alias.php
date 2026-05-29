<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen category_morph_counts.morph_alias from 64 to 255 so it matches the
 * (Laravel-default 255) categorizables.categorizable_type column. At 64 a long
 * morph alias — typically an unregistered fully-qualified class name — truncates
 * silently on MySQL (strict mode off) or throws (strict mode on), diverging the
 * counts table from the pivot. See audit finding B-3. The MaintainCategoryCounts
 * listener also guards alias length defensively; this is the storage half.
 *
 * SQLite does not enforce varchar length, so the limit never bites there; the
 * column is also part of the composite primary key, where a SQLite ->change()
 * table-rebuild is needless churn. We therefore skip SQLite entirely.
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->isSqlite()) {
            return;
        }

        Schema::table('category_morph_counts', function (Blueprint $table) {
            $table->string('morph_alias', 255)->change();
        });
    }

    public function down(): void
    {
        if ($this->isSqlite()) {
            return;
        }

        Schema::table('category_morph_counts', function (Blueprint $table) {
            $table->string('morph_alias', 64)->change();
        });
    }

    private function isSqlite(): bool
    {
        return Schema::getConnection()->getDriverName() === 'sqlite';
    }
};
