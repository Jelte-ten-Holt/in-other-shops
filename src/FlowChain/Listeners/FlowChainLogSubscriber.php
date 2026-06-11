<?php

declare(strict_types=1);

namespace InOtherShops\FlowChain\Listeners;

use InOtherShops\FlowChain\Events\FlowChainCompleted;
use InOtherShops\FlowChain\Events\FlowChainFailed;
use InOtherShops\FlowChain\Events\FlowChainStarted;
use InOtherShops\FlowChain\Events\FlowChainStepFailed;
use InOtherShops\Logging\Enums\LogLevel;
use InOtherShops\Logging\LogSubscriberBase;
use Illuminate\Contracts\Events\Dispatcher;

final class FlowChainLogSubscriber extends LogSubscriberBase
{
    protected const string CHANNEL = 'flowchain';

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
        $this->log(LogLevel::Info, "FlowChain started: {$event->flowName}.", [
                'flow' => $event->flowName,
            ]);
    }

    public function handleCompleted(FlowChainCompleted $event): void
    {
        $this->log(LogLevel::Info, "FlowChain completed: {$event->flowName}.", [
                'flow' => $event->flowName,
                'status' => $event->result->status->value,
                'steps' => count($event->result->steps),
                'duration_ms' => $event->result->durationMs,
            ]);
    }

    public function handleFailed(FlowChainFailed $event): void
    {
        $this->log(LogLevel::Error, "FlowChain failed: {$event->flowName}.", [
                'flow' => $event->flowName,
                'failed_step' => $event->result->failedStep,
                'exception' => $event->result->exception?->getMessage(),
                'duration_ms' => $event->result->durationMs,
            ]);
    }

    public function handleStepFailed(FlowChainStepFailed $event): void
    {
        $this->log(LogLevel::Warning, "FlowChain step failed: {$event->stepClass}.", [
                'flow' => $event->flowName,
                'step' => $event->stepClass,
                'exception' => $event->exception->getMessage(),
            ]);
    }
}
