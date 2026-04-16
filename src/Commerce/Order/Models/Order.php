<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Models;

use InOtherShops\Commerce\Commerce;
use InOtherShops\Commerce\Database\Factories\OrderFactory;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Location\Concerns\InteractsWithAddresses;
use InOtherShops\Location\Contracts\HasAddresses;
use InOtherShops\Location\Enums\AddressType;
use InOtherShops\Payment\Concerns\InteractsWithPayments;
use InOtherShops\Payment\Contracts\HasPayments;
use InOtherShops\Shipping\Concerns\InteractsWithShipment;
use InOtherShops\Shipping\Contracts\HasShipment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Order extends Model implements HasAddresses, HasPayments, HasShipment
{
    use HasFactory;
    use InteractsWithAddresses;
    use InteractsWithPayments;
    use InteractsWithShipment;

    protected $guarded = [];

    protected static function newFactory(): Factory
    {
        return new OrderFactory;
    }

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'currency' => Currency::class,
            'subtotal' => 'integer',
            'tax' => 'integer',
            'discount' => 'integer',
            'total' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Commerce::customer());
    }

    public function lines(): HasMany
    {
        return $this->hasMany(Commerce::orderLine());
    }

    public function shippingAddress(): MorphMany
    {
        return $this->addresses()->whereIn('type', [AddressType::Shipping, AddressType::ShippingAndBilling]);
    }

    public function billingAddress(): MorphMany
    {
        return $this->addresses()->whereIn('type', [AddressType::Billing, AddressType::ShippingAndBilling]);
    }

    public function getPaymentTotalDue(): int
    {
        return (int) $this->total;
    }
}
