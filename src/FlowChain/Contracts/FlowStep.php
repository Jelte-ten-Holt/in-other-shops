<?php

declare(strict_types=1);

namespace InOtherShops\FlowChain\Contracts;

interface FlowStep
{
    public function handle(FlowPayload $payload): void;
}
