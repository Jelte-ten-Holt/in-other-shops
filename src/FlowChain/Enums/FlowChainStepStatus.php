<?php

declare(strict_types=1);

namespace InOtherShops\FlowChain\Enums;

enum FlowChainStepStatus: string
{
    case Completed = 'completed';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
