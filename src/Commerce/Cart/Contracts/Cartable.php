<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\Contracts;

use InOtherShops\Currency\Enums\Currency;

interface Cartable
{
    public function getCartableLabel(): string;

    public function getCartableDescription(): ?string;

    /**
     * Unit price in the smallest currency subunit (e.g. cents) for the given
     * currency, or null when no price is available. The cartable is responsible
     * for resolving how it gets priced — Commerce stays agnostic.
     */
    public function getCartableUnitPrice(Currency $currency): ?int;
}
