<?php

declare(strict_types=1);

namespace InOtherShops\FlowChain\Listeners;

use InOtherShops\FlowChain\Events\FlowChainCompleted;
use InOtherShops\FlowChain\Events\FlowChainFailed;
use InOtherShops\FlowChain\Events\FlowChainStarted;
use InOtherShops\FlowChain\Events\FlowChainStepFailed;
use InOtherShops\Logging\DTOs\LogEntry;
use InOtherShops\Logging\Enums\LogLevel;
use InOtherShops\Logging\LogDispatcher;
use Illuminate\Contracts\Events\Dispatcher;

final class FlowChainLogSubscriber
{
    private const string CHANNEL = 'flowchain';

    public function __construct(
        private readonly LogDispatcher $dispatcher,
    ) {}

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            FlowChainStarted::class => 'handleStarted',
            FlowChainCompleted::class => 'handleCompleted',
            FlowChainFailed::class => 'handleFailed',
            FlowChainStepFailed::class => 'handleStepFailed',
        ];
    }

    public function handleStarted(FlowChainStarted $event): void
    {
        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Info,
            channel: self::CHANNEL,
            message: "FlowChain started: {$event->flowName}.",
            context: [
                'flow' => $event->flowName,
            ],
        ));
    }

    public function handleCompleted(FlowChainCompleted $event): void
    {
        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Info,
            channel: self::CHANNEL,
            message: "FlowChain completed: {$event->flowName}.",
            context: [
                'flow' => $event->flowName,
                'status' => $event->result->status->value,
                'steps' => count($event->result->steps),
                'duration_ms' => $event->result->durationMs,
            ],
        ));
    }

    public function handleFailed(FlowChainFailed $event): void
    {
        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Error,
            channel: self::CHANNEL,
            message: "FlowChain failed: {$event->flowName}.",
            context: [
                'flow' => $event->flowName,
                'failed_step' => $event->result->failedStep,
                'exception' => $event->result->exception?->getMessage(),
                'duration_ms' => $event->result->durationMs,
            ],
        ));
    }

    public function handleStepFailed(FlowChainStepFailed $event): void
    {
        $this->dispatcher->log(new LogEntry(
            level: LogLevel::Warning,
            channel: self::CHANNEL,
            message: "FlowChain step failed: {$event->stepClass}.",
            context: [
                'flow' => $event->flowName,
                'step' => $event->stepClass,
                'exception' => $event->exception->getMessage(),
            ],
        ));
    }
}
