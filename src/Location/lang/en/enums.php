<?php

declare(strict_types=1);

/**
 * Location enum labels — `en` source of truth. Values mirror each enum case's
 * current rendered label (incl. the AddressType::ShippingAndBilling ampersand
 * override). Keys mirror en/enums.php exactly across locales.
 */
return [
    'AddressType' => [
        'shipping' => 'Shipping',
        'billing' => 'Billing',
        'shipping_and_billing' => 'Shipping & Billing',
    ],
];
