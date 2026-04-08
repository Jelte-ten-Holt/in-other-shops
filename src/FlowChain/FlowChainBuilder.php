<?php

declare(strict_types=1);

namespace InOtherShops\FlowChain;

use InOtherShops\FlowChain\Contracts\FlowPayload;
use InOtherShops\FlowChain\Contracts\FlowStep;
use InOtherShops\FlowChain\DTOs\FlowChainResult;

final class FlowChainBuilder
{
    /** @var list<array{class: class-string<FlowStep>, condition: ?\Closure}> */
    private array $steps = [];

    private string $name = 'unnamed';

    private bool $useTransaction = false;

    private bool $dispatchEvents = true;

    /**
     * @param  class-string<FlowStep>  $stepClass
     */
    public function step(string $stepClass): self
    {
        $this->steps[] = ['class' => $stepClass, 'condition' => null];

        return $this;
    }

    /**
     * Register one or more steps that only run when the condition returns true.
     *
     * Single step:
     *   ->when(fn (Payload $p) => $p->hasVoucher, ApplyVoucher::class)
     *
     * Multiple steps:
     *   ->when(fn (Payload $p) => $p->isPhysical, fn (FlowChainBuilder $b) => $b
     *       ->step(CalculateShipping::class)
     *       ->step(ReserveInventory::class)
     *   )
     *
     * @param  \Closure(FlowPayload): bool  $condition
     * @param  class-string<FlowStep>|(\Closure(FlowChainBuilder): void)  $step
     */
    public function when(\Closure $condition, string|\Closure $step): self
    {
        if (is_string($step)) {
            $this->steps[] = ['class' => $step, 'condition' => $condition];

            return $this;
        }

        $subBuilder = new self;
        $step($subBuilder);

        foreach ($subBuilder->steps as $subStep) {
            $this->steps[] = [
                'class' => $subStep['class'],
                'condition' => $this->composeConditions($condition, $subStep['condition']),
            ];
        }

        return $this;
    }

    /**
     * @param  \Closure(FlowPayload): bool  $parent
     * @param  (\Closure(FlowPayload): bool)|null  $child
     * @return \Closure(FlowPayload): bool
     */
    private function composeConditions(\Closure $parent, ?\Closure $child): \Closure
    {
        if ($child === null) {
            return $parent;
        }

        return fn (FlowPayload $payload): bool => $parent($payload) && $child($payload);
    }

    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function wrapInTransaction(): self
    {
        $this->useTransaction = true;

        return $this;
    }

    public function withoutEvents(): self
    {
        $this->dispatchEvents = false;

        return $this;
    }

    public function build(): FlowChain
    {
        return new FlowChain(
            steps: $this->steps,
            name: $this->name,
            useTransaction: $this->useTransaction,
            dispatchEvents: $this->dispatchEvents,
        );
    }

    public function run(FlowPayload $payload): FlowChainResult
    {
        return $this->build()->run($payload);
    }
}
