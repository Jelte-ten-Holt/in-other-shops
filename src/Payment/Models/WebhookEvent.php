<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Idempotency ledger for incoming webhooks. `(gateway, event_id)` unique;
 * `HandlePaymentWebhook` inserts here before dispatching — duplicate deliveries
 * collide on the unique index and are silently dropped.
 */
final class WebhookEvent extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }
}
