<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Contracts;

use InOtherShops\Payment\DTOs\PaymentCustomerData;

interface ManagesCustomers
{
    /**
     * Create a customer record at the payment gateway.
     *
     * @return string The gateway's customer ID.
     */
    public function createCustomer(PaymentCustomerData $data): string;
}
