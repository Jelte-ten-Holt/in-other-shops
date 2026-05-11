<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Payment Gateways
    |--------------------------------------------------------------------------
    |
    | Per-driver configuration keyed by gateway name. Each gateway's service
    | provider registers a factory via PaymentGatewayManager::extend(name, ...)
    | — typically gated on its SDK being installed and config being present.
    |
    | Example:
    |
    |   'gateways' => [
    |       'stripe' => [
    |           'secret' => env('STRIPE_SECRET'),
    |           'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    |       ],
    |   ],
    |
    */

    'gateways' => [
        'stripe' => [
            'secret' => env('STRIPE_SECRET'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        ],
    ],

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
    | Webhook Retention
    |--------------------------------------------------------------------------
    |
    | Days to keep entries in the `webhook_events` idempotency ledger. Older
    | rows are removed by `payment:prune-webhook-events`. Schedule daily.
    | Retention only needs to outlive the longest gateway-retry window the
    | shop accepts — Stripe retries for ~3 days; 90 is conservative.
    |
    */

    'webhook_retention_days' => env('PAYMENT_WEBHOOK_RETENTION_DAYS', 90),

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
