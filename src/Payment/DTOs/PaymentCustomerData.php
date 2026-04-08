<?php

declare(strict_types=1);

namespace InOtherShops\Payment\DTOs;

final readonly class PaymentCustomerData
{
    public function __construct(
        public string $email,
        public ?string $name = null,
        public ?string $phone = null,
    ) {}
}
