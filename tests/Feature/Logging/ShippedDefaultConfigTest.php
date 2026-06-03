<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Logging;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use InOtherShops\Logging\Contracts\LogHandler;
use InOtherShops\Logging\DTOs\LogEntry;
use InOtherShops\Logging\Enums\LogLevel;
use InOtherShops\Logging\Handlers\FileLogHandler;
use InOtherShops\Logging\LogDispatcher;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * Pin the *shipped* `config/domain-log.php` defaults. Every other Logging
 * test in this suite replaces the channel map in `defineEnvironment` with
 * a `RecordingLogHandler`, which means a typo in the shipped channel keys
 * (e.g. "comerce" instead of "commerce") would never trip the suite.
 *
 * This file boots the package's `LogDispatcher` with no overrides and asserts
 * the shipped channels resolve to working handlers.
 */
final class ShippedDefaultConfigTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function shipped_channels_list_matches_the_documented_domains(): void
    {
        $channels = config('domain-log.channels');

        // Each domain that ships a LogSubscriber must have a channel; if a
        // new subscriber is added (or one is removed) without updating the
        // shipped config, this test surfaces the drift.
        $this->assertSame(
            ['flowchain', 'commerce', 'inventory', 'purchasing', 'payment', 'agent'],
            array_keys($channels),
        );

        foreach ($channels as $channel => $handlers) {
            $this->assertNotEmpty($handlers, "Channel '{$channel}' must ship at least one handler.");
            foreach ($handlers as $entry) {
                $this->assertSame(FileLogHandler::class, $entry['handler'],
                    "Shipped channel '{$channel}' must route through FileLogHandler.");
            }
        }
    }

    #[Test]
    public function shipped_default_routes_unmapped_channels_to_file_log_handler(): void
    {
        $default = config('domain-log.default');

        $this->assertNotEmpty($default,
            'Shipped config must ship a default handler list — entries with unknown channels would otherwise be dropped.');
        $this->assertSame(FileLogHandler::class, $default[0]['handler']);
    }

    #[Test]
    public function the_dispatcher_resolves_with_a_handler_per_shipped_channel(): void
    {
        // Boot the dispatcher against the shipped config (no overrides) and
        // inspect its handler map. This is the load-bearing assertion: a
        // misspelled channel key, a non-existent handler class, or a wrong
        // `with` arg in the shipped config would all surface as a resolution
        // error here, where `LogSubscriberMappingTest` would not see it.
        $dispatcher = $this->app->make(LogDispatcher::class);

        $reflected = new ReflectionClass($dispatcher);
        $handlers = $reflected->getProperty('handlers');
        $handlers->setAccessible(true);
        $map = $handlers->getValue($dispatcher);

        $this->assertSame(
            ['flowchain', 'commerce', 'inventory', 'purchasing', 'payment', 'agent'],
            array_keys($map),
        );
        foreach ($map as $channel => $list) {
            $this->assertNotEmpty($list, "Channel '{$channel}' resolved to no handlers.");
            $this->assertInstanceOf(LogHandler::class, $list[0]);
        }
    }

    #[Test]
    public function dispatching_an_entry_through_a_shipped_channel_reaches_the_log_facade(): void
    {
        // A regression that broke the FileLogHandler constructor signature
        // or the channel-name → Laravel-channel mapping would fail here. We
        // assert the side effect, not just "no throw" — that's what makes
        // this a real test rather than an `expectNotToPerformAssertions`
        // smoke (per docs/writing-tests.md).
        Log::shouldReceive('channel')
            ->once()
            ->with('commerce')
            ->andReturnSelf();
        Log::shouldReceive('log')
            ->once()
            ->with(
                'info',
                'ShippedDefaultConfigTest probe entry',
                ['ref' => 'probe-1'],
            );

        $dispatcher = $this->app->make(LogDispatcher::class);

        $dispatcher->log(new LogEntry(
            level: LogLevel::Info,
            channel: 'commerce',
            message: 'ShippedDefaultConfigTest probe entry',
            context: ['ref' => 'probe-1'],
        ));
    }
}
