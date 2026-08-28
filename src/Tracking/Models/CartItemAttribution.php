<?php

declare(strict_types=1);

namespace InOtherShops\Tracking\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use InOtherShops\Commerce\Commerce;
use InOtherShops\Tracking\Database\Factories\CartItemAttributionFactory;

/**
 * Polymorphic attribution for "what drove this cart add".
 *
 * Written by RecordCartItemAttribution (an AddToCartChain step) when the
 * cart-add request carries `metadata.source`. One row per cart_item;
 * first-source-wins, so quantity bumps never overwrite the original source.
 * Cascade-deletes with the cart_item, and is snapshotted onto an
 * OrderLineAttribution at checkout — this row is the volatile half.
 *
 * `source` is whatever the consumer's morph map allows: content, category and
 * tag in in-other-worlds; category and tag in bianka. A null source means the
 * add came from a surface with no attributable origin (direct PDP, cart
 * drawer, a shared link).
 */
class CartItemAttribution extends Model
{
    use HasFactory;

    protected $guarded = [];

    /**
     * There is no `updated_at`: a row is written once and never edited (the
     * unique key on cart_item_id is what enforces that), so `created_at` is
     * stamped explicitly by the step rather than by Eloquent.
     */
    public $timestamps = false;

    protected static string $factory = CartItemAttributionFactory::class;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function cartItem(): BelongsTo
    {
        return $this->belongsTo(Commerce::cartItem());
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
