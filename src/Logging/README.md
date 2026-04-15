# Logging Domain

A dispatch and routing layer for structured application logging. The domain provides a `LogEntry` DTO, a `LogHandler` contract, and a `LogDispatcher` service that routes entries to configured handlers by channel.

The Logging domain knows nothing about other domains. It receives structured log entries and sends them to the right destination.

## Architecture

```
Domain events ──→ Project subscribers ──→ LogDispatcher ──→ Handler(s) ──→ Destination
(FlowChain,       (transform event        (routes by        (File, DB,    (log file,
 Commerce)         to LogEntry)            channel)           etc.)         table, etc.)
```

**Key boundary:** Domain code never calls `LogDispatcher` directly. Domains fire events to signal state changes; project-level subscribers in `app/Listeners/Logging/` transform those events into `LogEntry` DTOs and pass them to `LogDispatcher`. This keeps domains independent of the logging infrastructure — a project that doesn't use this domain simply has no subscribers, and the events still fire harmlessly.

The same pattern works for project-specific events (e.g., "Product updated"). Create a subscriber that listens to your event and logs it — no domain changes needed.

## Models and Relationships

No Eloquent models. This domain is purely a dispatch layer.

## Key Classes

- **`DTOs\LogEntry`** — structured envelope: level, channel, message, context.
- **`Enums\LogLevel`** — PSR-3 compatible levels (Debug through Critical).
- **`Contracts\LogHandler`** — interface for destinations (`handle(LogEntry): void`).
- **`Handlers\FileLogHandler`** — writes to a named Laravel log channel.
- **`Handlers\FilteredLogHandler`** — decorator that filters entries by log level before passing to an inner handler.
- **`LogDispatcher`** — routes entries to handlers by channel, with a default fallback.

## Configuration

`config/domain-log.php` maps logical channel names to handler instances:

```php
'channels' => [
    'flowchain' => [
        ['handler' => FileLogHandler::class, 'with' => ['channel' => 'flowchain']],
    ],
],
'default' => [
    ['handler' => FileLogHandler::class, 'with' => ['channel' => 'daily']],
],
```

Adding a new destination (database, external service) = implement `LogHandler` and add it to the config. No subscriber changes needed.

### Level filtering

Add a `levels` key to any handler entry to restrict which log levels it receives. Handlers without `levels` receive everything.

```php
'commerce' => [
    ['handler' => FileLogHandler::class, 'with' => ['channel' => 'commerce']],
    ['handler' => DatabaseLogHandler::class, 'with' => [], 'levels' => ['error', 'critical']],
],
```

In this example, the file handler logs all commerce entries. The database handler only receives errors and criticals. The subscribers don't change — routing is purely a config concern.

## Wiring Up a New Log Subscriber

When a domain fires events you want to log, create a subscriber in `app/Listeners/Logging/` (auto-discovered by Laravel). Three steps:

### 1. Create the subscriber

Laravel 11+ auto-discovers listeners in `app/Listeners/**`: any public method whose first parameter type-hints an event class is automatically wired up. No `subscribe()` method, no `Event::subscribe()` registration — just inject `LogDispatcher` and write `handle*` methods that type-hint the events you care about.

```php
// app/Listeners/Logging/InventoryLogSubscriber.php

final class InventoryLogSubscriber
{
    private const string CHANNEL = 'inventory';

    public function __construct(
        private readonly LogDispatcher $dispatcher,
    ) {}

    public function handleStockAdjusted(StockAdjusted $event): void
    {
        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Info,
            channel: self::CHANNEL,
            message: "Stock adjusted: {$event->movement->reason->value}.",
            context: [
                'quantity' => $event->movement->quantity,
                'stock_level' => $event->stockItem->stock_level,
            ],
        ));
    }

    public function handleStockReleased(StockReleased $event): void
    {
        // ...
    }
}
```

The class doesn't need to extend or implement anything. Method names are conventional (`handle*`) but only the type-hint matters for discovery.

> **Do not combine auto-discovery with an explicit `subscribe(Dispatcher $events)` method that calls `$events->listen(...)`.** That registers the same handler twice and causes **double dispatch** — every event fires the log entry twice. Pick one.

Use the explicit `subscribe()` pattern only as a fallback for listeners that live outside `app/Listeners/**`, or when you need to register listeners conditionally.

### 2. Add a dedicated log channel (optional)

If the channel should write to its own file, add it in two places:

**`config/logging.php`** — the Laravel log channel:
```php
'inventory' => [
    'driver' => 'daily',
    'path' => storage_path('logs/inventory.log'),
    'level' => env('LOG_LEVEL', 'debug'),
    'days' => env('LOG_DAILY_DAYS', 14),
    'replace_placeholders' => true,
],
```

**`config/domain-log.php`** — route the logical channel to the handler:
```php
'inventory' => [
    ['handler' => FileLogHandler::class, 'with' => ['channel' => 'inventory']],
],
```

Skip this step if the events aren't important enough for their own file — they'll fall through to the `default` handler (daily log).

### 3. Test it

Follow the same pattern as the existing subscriber tests. Replace the `LogDispatcher` singleton with a test handler that captures entries:

```php
$this->handler = new TestLogHandler;

$this->app->singleton(LogDispatcher::class, fn () => new LogDispatcher(
    handlers: ['inventory' => [$this->handler]],
    default: [$this->handler],
));

// dispatch event, then assert against $this->handler->entries
```

## Design Decisions

- **No Eloquent models** — the domain is a routing layer, not a storage layer. A `DatabaseLogHandler` can be added later without changing the domain's core.
- **Channel-based routing** — subscribers tag entries with a logical channel. The config decides where each channel goes. This decouples "what to log" from "where to log it."
- **Default fallback** — entries for unmapped channels go to the default handlers rather than being silently dropped.

## Future

- `DatabaseLogHandler` for a queryable audit trail.
- Context enrichment middleware (request ID, user ID).
- Filament log viewer.
