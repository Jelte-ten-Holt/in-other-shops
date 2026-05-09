<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs;

use InOtherShops\Logging\Contracts\LogHandler;
use InOtherShops\Logging\DTOs\LogEntry;

/**
 * Test handler that captures every dispatched LogEntry in memory so the
 * test can assert level, channel, and context. Bound as a container
 * singleton in tests that exercise log subscribers; the bindings in the
 * domain-log config use this class so every subscribed event funnels here.
 */
final class RecordingLogHandler implements LogHandler
{
    /** @var list<LogEntry> */
    private array $entries = [];

    public function handle(LogEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    /** @return list<LogEntry> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function reset(): void
    {
        $this->entries = [];
    }

    public function lastEntry(): LogEntry
    {
        if ($this->entries === []) {
            throw new \LogicException('No log entries have been recorded.');
        }

        return $this->entries[array_key_last($this->entries)];
    }

    /** @return list<LogEntry> */
    public function entriesForChannel(string $channel): array
    {
        return array_values(array_filter(
            $this->entries,
            fn (LogEntry $entry): bool => $entry->channel === $channel,
        ));
    }
}
