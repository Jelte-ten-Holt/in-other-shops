<?php

declare(strict_types=1);

namespace InOtherShops\Tracking\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use InOtherShops\Commerce\Commerce;
use InOtherShops\Tracking\Database\Factories\OrderLineAttributionFactory;

/**
 * Snapshotted attribution on an order line — the durable half of the pair.
 *
 * Written by SnapshotCartItemAttributions (a checkout step) from the matching
 * CartItemAttribution at order creation, so the attribution survives the cart
 * being cleared after payment. One row per order_line.
 *
 * This is the table a read surface should query: "what drove which purchases"
 * is a question about orders, not carts.
 */
class OrderLineAttribution extends Model
{
    use HasFactory;

    protected $guarded = [];

    /** Write-once, like CartItemAttribution — see the note there. */
    public $timestamps = false;

    protected static string $factory = OrderLineAttributionFactory::class;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(Commerce::orderLine());
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
