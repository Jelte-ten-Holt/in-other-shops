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
        // Drop before create, and drop EVERYTHING before creating anything.
        //
        // In-memory SQLite hands each test a pristine database, so a stub table
        // can never pre-exist. MySQL keeps one database for the whole process
        // and its DDL is not transactional, so a stub table created under a
        // RefreshDatabase transaction survives that test's rollback and the next
        // migration run dies on 1050 "table already exists". Making up()
        // idempotent is safe here in a way it would never be in a shipped
        // migration: these tables hold only fixture data, and the suite is the
        // only thing that ever touches them.
        //
        // The two loops are separate because stub tables carry foreign keys;
        // dropping and re-creating one at a time would fail whenever a table
        // still referenced one already gone.
        $tables = array_map(
            static fn (string $class): string => (new $class)->getTable(),
            StubModel::stubClasses(),
        );

        foreach (array_reverse($tables) as $table) {
            Schema::dropIfExists($table);
        }

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
