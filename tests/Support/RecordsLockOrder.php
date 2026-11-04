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
 * `for update`. Each entry is `lock:{table}` (`lock:stock_items#{type}:{id}` for a
 * stock item), `read:{table}` (a select without a lock), `insert:{table}` or
 * `update:{table}`.
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
                $this->lockOrder[] = self::describeQuery($query->sql, $query->bindings);
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
            static fn (string $entry): bool => in_array(self::tableOf($entry), $tables, true),
        ));
    }

    /**
     * Assert the brief's §4.2 lock order on what was recorded:
     *
     *  - the first lock on each table comes in the given table order;
     *  - **every** stock item the run locks is first locked before the first
     *    reservation-row lock — not just the first one — and the stock items
     *    are first locked in the global sort (morph class, key);
     *  - no plain read of `stock_reservations` falls between its lock and the
     *    first reservation insert (a consistent read there is a pre-wait
     *    snapshot).
     *
     * @param  list<string>  $expected  tables in the order their first lock must appear
     */
    protected function assertLocksTakenInOrder(array $expected): void
    {
        $order = $this->recordedLockOrder();

        $firstLocks = [];
        foreach ($order as $index => $entry) {
            if (str_starts_with($entry, 'lock:')) {
                $firstLocks[self::tableOf($entry)] ??= $index;
            }
        }

        $this->assertStockItemsLockedFirstAndSorted($order, $firstLocks['stock_reservations'] ?? null);

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

    /**
     * @param  list<string>  $order
     */
    private function assertStockItemsLockedFirstAndSorted(array $order, ?int $firstRowLock): void
    {
        $firstItemLocks = [];

        foreach ($order as $index => $entry) {
            if (str_starts_with($entry, 'lock:stock_items#')) {
                $firstItemLocks[substr($entry, strlen('lock:stock_items#'))] ??= $index;
            }
        }

        if ($firstRowLock !== null) {
            foreach ($firstItemLocks as $item => $index) {
                $this->assertLessThan($firstRowLock, $index, "Stock item {$item} first locked after a reservation row. Recorded: ".implode(', ', $order));
            }
        }

        $items = array_map(static function (string $item): array {
            [$type, $id] = explode(':', $item, 2);

            return [$type, (int) $id];
        }, array_keys($firstItemLocks));
        $sorted = $items;
        usort($sorted, static fn (array $a, array $b): int => $a <=> $b);

        $this->assertSame($sorted, $items, 'Stock items not locked in the global order. Recorded: '.implode(', ', $order));
    }

    private static function tableOf(string $entry): string
    {
        $afterVerb = substr($entry, strpos($entry, ':') + 1);

        return explode('#', $afterVerb, 2)[0];
    }

    /**
     * @param  array<int, mixed>  $bindings
     */
    private static function describeQuery(string $sql, array $bindings = []): string
    {
        $normalised = strtolower($sql);
        $table = static fn (string $pattern): string => preg_match($pattern, $normalised, $m) === 1 ? $m[1] : '?';

        return match (true) {
            // A stock item lock names its item, so the order of items can be
            // asserted, not only the order of tables.
            str_starts_with($normalised, 'select') && str_contains($normalised, 'for update') && $table('/ from [`"]?(\w+)[`"]?/') === 'stock_items' && count($bindings) >= 2 => 'lock:stock_items#'.$bindings[0].':'.$bindings[1],
            str_starts_with($normalised, 'select') && str_contains($normalised, 'for update') => 'lock:'.$table('/ from [`"]?(\w+)[`"]?/'),
            str_starts_with($normalised, 'select') => 'read:'.$table('/ from [`"]?(\w+)[`"]?/'),
            str_starts_with($normalised, 'insert') => 'insert:'.$table('/^insert into [`"]?(\w+)[`"]?/'),
            str_starts_with($normalised, 'update') => 'update:'.$table('/^update [`"]?(\w+)[`"]?/'),
            default => 'other:'.$normalised,
        };
    }
}
