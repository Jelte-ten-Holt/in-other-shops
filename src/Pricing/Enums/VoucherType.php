<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Enums;

enum VoucherType: string
{
    case Fixed = 'fixed';
    case Percentage = 'percentage';
}
