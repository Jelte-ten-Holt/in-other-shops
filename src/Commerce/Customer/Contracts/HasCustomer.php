<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Customer\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphOne;

interface HasCustomer
{
    public function customer(): MorphOne;
}
