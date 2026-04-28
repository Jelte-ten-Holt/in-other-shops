<?php

declare(strict_types=1);

namespace InOtherShops\Tax\Enums;

enum TaxCategory: string
{
    case PhysicalGoods = 'physical_goods';
    case DigitalServices = 'digital_services';
}
