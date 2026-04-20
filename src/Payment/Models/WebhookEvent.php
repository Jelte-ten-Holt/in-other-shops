<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Models;

use InOtherShops\Payment\Database\Factories\WebhookEventFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Idempotency ledger for incoming webhooks. `(gateway, event_id)` unique;
 * `HandlePaymentWebhook` inserts here before dispatching — duplicate deliveries
 * collide on the unique index and are silently dropped.
 */
final class WebhookEvent extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): Factory
    {
        return new WebhookEventFactory;
    }

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }
}
