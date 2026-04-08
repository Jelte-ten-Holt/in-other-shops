<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Payment Gateway
    |--------------------------------------------------------------------------
    |
    | The FQCN of the PaymentGateway implementation to use. The service
    | provider binds this class to the PaymentGateway contract.
    |
    */

    'gateway' => env('PAYMENT_GATEWAY'),

    /*
    |--------------------------------------------------------------------------
    | Webhook Tolerance
    |--------------------------------------------------------------------------
    |
    | Maximum age in seconds for a webhook to be considered valid.
    | Webhooks older than this will be rejected.
    |
    */

    'webhook_tolerance' => env('PAYMENT_WEBHOOK_TOLERANCE', 300),

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Override the default models used by the Payment domain. Each value
    | must be a class that extends the corresponding base model.
    |
    */

    'models' => [
        'payment' => InOtherShops\Payment\Models\Payment::class,
        'payment_profile' => InOtherShops\Payment\Models\PaymentProfile::class,
    ],
];
