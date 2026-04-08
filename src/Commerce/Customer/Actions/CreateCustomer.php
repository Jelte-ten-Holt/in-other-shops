<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Customer\Actions;

use InOtherShops\Commerce\Commerce;
use InOtherShops\Commerce\Customer\Events\CustomerCreated;
use InOtherShops\Commerce\Customer\Models\Customer;

final class CreateCustomer
{
    public function __invoke(
        string $name,
        string $email,
        ?string $phone = null,
        ?int $customerGroupId = null,
    ): Customer {
        $customer = Commerce::customer()->query()->create([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'customer_group_id' => $customerGroupId,
        ]);

        CustomerCreated::dispatch($customer);

        return $customer;
    }
}
