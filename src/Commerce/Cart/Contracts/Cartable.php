<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\Contracts;

interface Cartable
{
    public function getCartableLabel(): string;

    public function getCartableDescription(): ?string;
}
