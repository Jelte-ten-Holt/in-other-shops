<?php

declare(strict_types=1);

use InOtherShops\Tests\Stubs\StubColumns;
use InOtherShops\Tests\Stubs\StubModel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * One migration for all 14 stub tables, replacing the 14 per-stub create-table
 * migrations. Each table is built from its stub's capability list via
 * {@see StubColumns::apply()}. Runs after the package's own migrations (later
 * timestamp), so the `locale_groups` FK target already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (StubModel::stubClasses() as $class) {
            Schema::create((new $class)->getTable(), function (Blueprint $table) use ($class): void {
                StubColumns::apply($table, $class::capabilities());
            });
        }
    }

    public function down(): void
    {
        foreach (StubModel::stubClasses() as $class) {
            Schema::dropIfExists((new $class)->getTable());
        }
    }
};
