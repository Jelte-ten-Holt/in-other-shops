<?php

declare(strict_types=1);

namespace InOtherShops\FlowChain\Enums;

enum FlowChainStatus: string
{
    case Completed = 'completed';
    case Failed = 'failed';
}
