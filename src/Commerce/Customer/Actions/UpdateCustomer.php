<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Customer\Actions;

use InOtherShops\Commerce\Customer\Events\CustomerUpdated;
use InOtherShops\Commerce\Customer\Models\Customer;

final class UpdateCustomer
{
    public function __invoke(
        Customer $customer,
        string $name,
        string $email,
        ?string $phone = null,
        ?int $customerGroupId = null,
    ): Customer {
        $customer->update([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'customer_group_id' => $customerGroupId,
        ]);

        CustomerUpdated::dispatch($customer);

        return $customer;
    }
}
