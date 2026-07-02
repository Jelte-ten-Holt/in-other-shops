<?php

declare(strict_types=1);

namespace InOtherShops\Location\Enums;

use InOtherShops\Support\HasLabel;

enum AddressType: string
{
    use HasLabel;

    case Shipping = 'shipping';
    case Billing = 'billing';
    case ShippingAndBilling = 'shipping_and_billing';

    // The ampersand can't be derived from the `shipping_and_billing` value;
    // every other case falls through to the sentence-case default.
    public function label(): string
    {
        return match ($this) {
            self::ShippingAndBilling => 'Shipping & Billing',
            default => $this->defaultLabel(),
        };
    }
}
