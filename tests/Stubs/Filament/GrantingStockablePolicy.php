<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs\Filament;

/**
 * A policy that grants the read ability — stands in for a consumer policy that
 * deliberately exposes a package model to a panel user.
 */
final class GrantingStockablePolicy
{
    public function viewAny(mixed $user): bool
    {
        return true;
    }
}
