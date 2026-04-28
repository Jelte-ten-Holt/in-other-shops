<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Contracts;

interface HasShippability
{
    public function requiresShipping(): bool;
}
