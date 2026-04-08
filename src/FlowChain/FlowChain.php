<?php

declare(strict_types=1);

namespace InOtherShops\FlowChain;

use InOtherShops\FlowChain\Contracts\FlowPayload;
use InOtherShops\FlowChain\Contracts\FlowStep;
use InOtherShops\FlowChain\DTOs\FlowChainResult;
use InOtherShops\FlowChain\DTOs\FlowChainStepResult;
use InOtherShops\FlowChain\Enums\FlowChainStatus;
use InOtherShops\FlowChain\Enums\FlowChainStepStatus;
use InOtherShops\FlowChain\Events\FlowChainCompleted;
use InOtherShops\FlowChain\Events\FlowChainFailed;
use InOtherShops\FlowChain\Events\FlowChainStarted;
use InOtherShops\FlowChain\Events\FlowChainStepCompleted;
use InOtherShops\FlowChain\Events\FlowChainStepFailed;
use InOtherShops\FlowChain\Exceptions\StepFailedException;
use Illuminate\Support\Facades\DB;

final class FlowChain
{
    /** @var list<array{class: class-string<FlowStep>, condition: ?\Closure}> */
    private array $steps;

    private string $name;

    private bool $useTransaction;

    private bool $dispatchEvents;

    /**
     * @param  list<array{class: class-string<FlowStep>, condition: ?\Closure}>  $steps
     */
    public function __construct(
        array $steps,
        string $name,
        bool $useTransaction,
        bool $dispatchEvents,
    ) {
        $this->steps = $steps;
        $this->name = $name;
        $this->useTransaction = $useTransaction;
        $this->dispatchEvents = $dispatchEvents;
    }

    public static function make(): FlowChainBuilder
    {
        return new FlowChainBuilder;
    }

    public function run(FlowPayload $payload): FlowChainResult
    {
        if ($this->useTransaction) {
            return DB::transaction(fn (): FlowChainResult => $this->execute($payload));
        }

        return $this->execute($payload);
    }

    private function execute(FlowPayload $payload): FlowChainResult
    {
        $startTime = hrtime(true);
        $stepResults = [];

        $this->fireEvent(new FlowChainStarted($this->name, $payload));

        foreach ($this->steps as $step) {
            $result = $this->executeStep($step, $payload, $stepResults);

            if ($result instanceof FlowChainResult) {
                return $result;
            }

            $stepResults[] = $result;
        }

        $durationMs = $this->elapsedMs($startTime);

        $flowResult = new FlowChainResult(
            status: FlowChainStatus::Completed,
            payload: $payload,
            steps: $stepResults,
            durationMs: $durationMs,
        );

        $this->fireEvent(new FlowChainCompleted($this->name, $flowResult));

        return $flowResult;
    }

    /**
     * @param  array{class: class-string<FlowStep>, condition: ?\Closure}  $step
     * @param  list<FlowChainStepResult>  $priorResults
     */
    private function executeStep(array $step, FlowPayload $payload, array $priorResults): FlowChainStepResult|FlowChainResult
    {
        $stepClass = $step['class'];

        if ($step['condition'] !== null && ! ($step['condition'])($payload)) {
            return new FlowChainStepResult(
                stepClass: $stepClass,
                status: FlowChainStepStatus::Skipped,
                durationMs: 0.0,
            );
        }

        $stepStart = hrtime(true);

        try {
            /** @var FlowStep $instance */
            $instance = app($stepClass);
            $instance->handle($payload);

            $durationMs = $this->elapsedMs($stepStart);

            $stepResult = new FlowChainStepResult(
                stepClass: $stepClass,
                status: FlowChainStepStatus::Completed,
                durationMs: $durationMs,
            );

            $this->fireEvent(new FlowChainStepCompleted($this->name, $stepClass, $durationMs));

            return $stepResult;
        } catch (\Throwable $e) {
            $durationMs = $this->elapsedMs($stepStart);

            $stepResult = new FlowChainStepResult(
                stepClass: $stepClass,
                status: FlowChainStepStatus::Failed,
                durationMs: $durationMs,
                error: $e->getMessage(),
            );

            $this->fireEvent(new FlowChainStepFailed($this->name, $stepClass, $e));

            $flowResult = new FlowChainResult(
                status: FlowChainStatus::Failed,
                payload: $payload,
                steps: [...$priorResults, $stepResult],
                failedStep: $stepClass,
                exception: new StepFailedException($stepClass, $e),
                durationMs: $durationMs,
            );

            $this->fireEvent(new FlowChainFailed($this->name, $flowResult));

            return $flowResult;
        }
    }

    private function elapsedMs(int $startNs): float
    {
        return (hrtime(true) - $startNs) / 1_000_000;
    }

    private function fireEvent(object $event): void
    {
        if ($this->dispatchEvents) {
            event($event);
        }
    }
}
