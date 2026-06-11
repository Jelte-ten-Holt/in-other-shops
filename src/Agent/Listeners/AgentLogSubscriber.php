<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use InOtherShops\Agent\Events\DynamicClientRegistered;
use InOtherShops\Agent\Events\ToolInvocationFailed;
use InOtherShops\Agent\Events\ToolInvoked;
use InOtherShops\Logging\Enums\LogLevel;
use InOtherShops\Logging\LogSubscriberBase;

final class AgentLogSubscriber extends LogSubscriberBase
{
    protected const string CHANNEL = 'agent';

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

        $this->log(LogLevel::Info, "Tool {$invocation->tool} invoked.", [
                'tool' => $invocation->tool,
                'input' => $invocation->redactedInput,
                'duration_ms' => round($invocation->durationMs, 2),
                'bearer_hash' => $invocation->bearerHash,
            ]);
    }

    public function handleToolInvocationFailed(ToolInvocationFailed $event): void
    {
        $invocation = $event->invocation;

        $this->log(LogLevel::Error, "Tool {$invocation->tool} failed: {$invocation->error}.", [
                'tool' => $invocation->tool,
                'input' => $invocation->redactedInput,
                'error' => $invocation->error,
                'duration_ms' => round($invocation->durationMs, 2),
                'bearer_hash' => $invocation->bearerHash,
            ]);
    }

    public function handleDynamicClientRegistered(DynamicClientRegistered $event): void
    {
        $this->log(LogLevel::Notice, "Dynamic client registered: {$event->clientName}.", [
                'client_id' => $event->clientId,
                'client_name' => $event->clientName,
                'redirect_uris' => $event->redirectUris,
                'confidential' => $event->isConfidential,
            ]);
    }
}
