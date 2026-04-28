<?php

declare(strict_types=1);

namespace InOtherShops\Tax\Contracts;

use InOtherShops\Tax\Enums\TaxCategory;

interface HasTaxCategory
{
    public function taxCategory(): TaxCategory;
}
