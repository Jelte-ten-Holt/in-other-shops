<?php

declare(strict_types=1);

namespace InOtherShops\Storefront\Contracts;

interface HasAvailability
{
    public function stockLevel(): int;

    public function isInStock(): bool;
}
