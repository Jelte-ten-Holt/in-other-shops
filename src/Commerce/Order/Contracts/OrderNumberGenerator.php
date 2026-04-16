<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Contracts;

interface OrderNumberGenerator
{
    public function __invoke(): string;
}
