<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Support;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Support\Facades\DB;

/**
 * Records the order in which an actor takes its row locks, for the lock-order
 * tests (stock-holds brief §7). Two connections in one process with a lock-wait
 * timeout would be the real concurrency probe; nothing in the suite does that,
 * so the order is asserted on the query log instead — sequential, and named so.
 *
 * SQLite compiles `lockForUpdate()` to nothing, so on SQLite the connection gets
 * a grammar that writes the lock as a trailing comment; MySQL already writes
 * `for update`. Each entry is `lock:{table}`, `read:{table}` (a select without a
 * lock), `insert:{table}` or `update:{table}`.
 */
trait RecordsLockOrder
{
    /** @var list<string> */
    private array $lockOrder = [];

    private bool $recordingLocks = false;

    protected function recordLockOrder(): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() === 'sqlite') {
            $connection->setQueryGrammar(new class($connection) extends SQLiteGrammar
            {
                protected function compileLock(Builder $query, $value): string
                {
                    return $value === true ? '/* for update */' : '';
                }
            });
        }

        $this->lockOrder = [];
        $this->recordingLocks = true;

        $connection->listen(function (QueryExecuted $query): void {
            if ($this->recordingLocks) {
                $this->lockOrder[] = self::describeQuery($query->sql);
            }
        });
    }

    /**
     * Stop recording and return what was recorded, without the queries no lock
     * argument is about (savepoints, other tables' plain reads).
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    protected function recordedLockOrder(array $tables = ['stock_holds', 'stock_items', 'stock_reservations']): array
    {
        $this->recordingLocks = false;

        return array_values(array_filter(
            $this->lockOrder,
            static fn (string $entry): bool => in_array(substr($entry, strpos($entry, ':') + 1), $tables, true),
        ));
    }

    /**
     * Assert the first lock on each table comes in the given table order, and
     * that no plain read of `stock_reservations` falls between its lock and the
     * first reservation insert (a consistent read there is a pre-wait snapshot).
     *
     * @param  list<string>  $expected  tables in the order their first lock must appear
     */
    protected function assertLocksTakenInOrder(array $expected): void
    {
        $order = $this->recordedLockOrder();

        $firstLocks = [];
        foreach ($order as $index => $entry) {
            if (str_starts_with($entry, 'lock:')) {
                $firstLocks[substr($entry, 5)] ??= $index;
            }
        }

        foreach ($expected as $table) {
            $this->assertArrayHasKey($table, $firstLocks, "No lock on {$table}. Recorded: ".implode(', ', $order));
        }

        $positions = array_map(fn (string $table): int => $firstLocks[$table], $expected);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'Locks out of order. Recorded: '.implode(', ', $order));

        $reservationLock = $firstLocks['stock_reservations'] ?? null;

        if ($reservationLock === null) {
            return;
        }

        foreach (array_slice($order, $reservationLock) as $entry) {
            if ($entry === 'insert:stock_reservations') {
                break;
            }

            $this->assertNotSame('read:stock_reservations', $entry, 'A non-locking read of stock_reservations between its lock and the reserve. Recorded: '.implode(', ', $order));
        }
    }

    private static function describeQuery(string $sql): string
    {
        $normalised = strtolower($sql);
        $table = static fn (string $pattern): string => preg_match($pattern, $normalised, $m) === 1 ? $m[1] : '?';

        return match (true) {
            str_starts_with($normalised, 'select') && str_contains($normalised, 'for update') => 'lock:'.$table('/ from [`"]?(\w+)[`"]?/'),
            str_starts_with($normalised, 'select') => 'read:'.$table('/ from [`"]?(\w+)[`"]?/'),
            str_starts_with($normalised, 'insert') => 'insert:'.$table('/^insert into [`"]?(\w+)[`"]?/'),
            str_starts_with($normalised, 'update') => 'update:'.$table('/^update [`"]?(\w+)[`"]?/'),
            default => 'other:'.$normalised,
        };
    }
}
