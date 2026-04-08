<?php

declare(strict_types=1);

namespace InOtherShops\Location\Enums;

enum AddressType: string
{
    case Shipping = 'shipping';
    case Billing = 'billing';
    case ShippingAndBilling = 'shipping_and_billing';

    public function label(): string
    {
        return match ($this) {
            self::Shipping => 'Shipping',
            self::Billing => 'Billing',
            self::ShippingAndBilling => 'Shipping & Billing',
        };
    }
}
