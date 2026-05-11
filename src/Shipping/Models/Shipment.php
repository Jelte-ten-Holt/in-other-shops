<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use InOtherShops\Shipping\Database\Factories\ShipmentFactory;
use InOtherShops\Shipping\Enums\ShipmentStatus;
use InOtherShops\Shipping\Shipping;

class Shipment extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): Factory
    {
        return new ShipmentFactory;
    }

    protected function casts(): array
    {
        return [
            'status' => ShipmentStatus::class,
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function shippable(): MorphTo
    {
        return $this->morphTo();
    }

    public function items(): HasMany
    {
        return $this->hasMany(Shipping::shipmentItem());
    }

    /**
     * Duck-typed check against the Payment domain's `HasPayments::isPaid()`.
     * Avoids a hard Shipping→Payment dependency in the domain graph — a
     * shippable that doesn't implement `isPaid()` (no Payment integration)
     * is allowed to dispatch.
     */
    public function shippableIsPaid(): bool
    {
        $shippable = $this->shippable;

        if ($shippable === null || ! method_exists($shippable, 'isPaid')) {
            return true;
        }

        return (bool) $shippable->isPaid();
    }
}
