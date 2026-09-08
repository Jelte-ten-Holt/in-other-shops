<?php

declare(strict_types=1);

namespace InOtherShops\FlowChain\Listeners;

use InOtherShops\FlowChain\Events\FlowChainFailed;
use InOtherShops\FlowChain\Events\FlowChainStepFailed;
use InOtherShops\Logging\Enums\LogLevel;
use InOtherShops\Logging\LogSubscriberBase;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Failures only. `FlowChainStarted`/`FlowChainCompleted` used to log at Info,
 * which on a consumer routing `flowchain` at the database handler meant two
 * rows for every add-to-cart and every checkout — pure volume, no signal. The
 * events still dispatch.
 */
final class FlowChainLogSubscriber extends LogSubscriberBase
{
    protected const string CHANNEL = 'flowchain';

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            FlowChainFailed::class => 'handleFailed',
            FlowChainStepFailed::class => 'handleStepFailed',
        ];
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
