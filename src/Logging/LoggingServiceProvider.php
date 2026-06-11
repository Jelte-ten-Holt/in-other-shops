<?php

declare(strict_types=1);

namespace InOtherShops\Logging;

use Illuminate\Console\Scheduling\Schedule;
use InOtherShops\Logging\Commands\PruneDomainLogsCommand;
use InOtherShops\Logging\Contracts\LogHandler;
use InOtherShops\Logging\Enums\LogLevel;
use InOtherShops\Logging\Handlers\FilteredLogHandler;
use InOtherShops\Support\DomainServiceProvider;

final class LoggingServiceProvider extends DomainServiceProvider
{
    protected function domainDir(): string
    {
        return __DIR__;
    }

    /** The one domain whose config key isn't its directory name. */
    protected function configKey(): string
    {
        return 'domain-log';
    }

    protected function domainCommands(): array
    {
        return [PruneDomainLogsCommand::class];
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(LogContext::class);

        $this->app->singleton(LogDispatcher::class, function (): LogDispatcher {
            $config = config('domain-log');

            $handlers = $this->buildHandlerMap($config['channels'] ?? []);
            $default = $this->buildHandlers($config['default'] ?? []);

            return new LogDispatcher($handlers, $default, $this->app->make(LogContext::class));
        });
    }

    public function boot(): void
    {
        parent::boot();

        // Keeps its historical 'logging-config' tag (the derived tag would
        // be 'domain-log-config'), so the publish stays hand-rolled.
        $this->publishes([
            __DIR__.'/config/domain-log.php' => config_path('domain-log.php'),
        ], 'logging-config');

        $this->scheduleWhenEnabled(function (Schedule $schedule): void {
            $schedule->command('logging:prune-domain-logs')->daily();
        });
    }

    /**
     * @param  array<string, list<array{handler: class-string<LogHandler>, with: array<string, mixed>}>>  $channels
     * @return array<string, list<LogHandler>>
     */
    private function buildHandlerMap(array $channels): array
    {
        $map = [];

        foreach ($channels as $channel => $handlerConfigs) {
            $map[$channel] = $this->buildHandlers($handlerConfigs);
        }

        return $map;
    }

    /**
     * @param  list<array{handler: class-string<LogHandler>, with: array<string, mixed>, levels?: list<string>}>  $configs
     * @return list<LogHandler>
     */
    private function buildHandlers(array $configs): array
    {
        $handlers = [];

        foreach ($configs as $config) {
            $handler = $this->app->make($config['handler'], $config['with'] ?? []);

            if (! empty($config['levels'])) {
                $handler = $this->wrapWithLevelFilter($handler, $config['levels']);
            }

            $handlers[] = $handler;
        }

        return $handlers;
    }

    /**
     * @param  list<string>  $levels
     */
    private function wrapWithLevelFilter(LogHandler $handler, array $levels): FilteredLogHandler
    {
        return new FilteredLogHandler(
            inner: $handler,
            levels: array_map(fn (string $level): LogLevel => LogLevel::from($level), $levels),
        );
    }
}
