<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use InOtherShops\Agent\Events\DynamicClientRegistered;
use InOtherShops\Agent\Events\ToolInvocationFailed;
use InOtherShops\Agent\Events\ToolInvoked;
use InOtherShops\Logging\DTOs\LogEntry;
use InOtherShops\Logging\Enums\LogLevel;
use InOtherShops\Logging\LogDispatcher;

final class AgentLogSubscriber
{
    private const string CHANNEL = 'agent';

    public function __construct(
        private readonly LogDispatcher $dispatcher,
    ) {}

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            ToolInvoked::class => 'handleToolInvoked',
            ToolInvocationFailed::class => 'handleToolInvocationFailed',
            DynamicClientRegistered::class => 'handleDynamicClientRegistered',
        ];
    }

    public function handleToolInvoked(ToolInvoked $event): void
    {
        $invocation = $event->invocation;

        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Info,
            channel: self::CHANNEL,
            message: "Tool {$invocation->tool} invoked.",
            context: [
                'tool' => $invocation->tool,
                'input' => $invocation->redactedInput,
                'duration_ms' => round($invocation->durationMs, 2),
                'bearer_hash' => $invocation->bearerHash,
            ],
        ));
    }

    public function handleToolInvocationFailed(ToolInvocationFailed $event): void
    {
        $invocation = $event->invocation;

        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Error,
            channel: self::CHANNEL,
            message: "Tool {$invocation->tool} failed: {$invocation->error}.",
            context: [
                'tool' => $invocation->tool,
                'input' => $invocation->redactedInput,
                'error' => $invocation->error,
                'duration_ms' => round($invocation->durationMs, 2),
                'bearer_hash' => $invocation->bearerHash,
            ],
        ));
    }

    public function handleDynamicClientRegistered(DynamicClientRegistered $event): void
    {
        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Notice,
            channel: self::CHANNEL,
            message: "Dynamic client registered: {$event->clientName}.",
            context: [
                'client_id' => $event->clientId,
                'client_name' => $event->clientName,
                'redirect_uris' => $event->redirectUris,
                'confidential' => $event->isConfidential,
            ],
        ));
    }
}
