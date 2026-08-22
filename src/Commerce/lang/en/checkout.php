<?php

declare(strict_types=1);

// Shopper-facing checkout strings (the voucher endpoints translate
// server-side — the storefront renders the error bag verbatim). Override
// wording per consumer via lang/vendor/shops-commerce/{locale}/checkout.php.
return [
    'voucher' => [
        'errors' => [
            'not_found' => "We don't recognise this code.",
            'invalid' => 'This code can no longer be used.',
            'minimum' => 'This code needs a minimum order of :amount.',
            'throttled' => 'Too many attempts. Try again in :seconds seconds.',
        ],
    ],
];
