<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Customer\Events;

use InOtherShops\Commerce\Customer\Models\Customer;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class CustomerUpdated
{
    use Dispatchable;

    public function __construct(
        public Customer $customer,
    ) {}
}
