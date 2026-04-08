<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Customer\Models;

use InOtherShops\Commerce\Commerce;
use InOtherShops\Location\Concerns\InteractsWithAddresses;
use InOtherShops\Location\Contracts\HasAddresses;
use InOtherShops\Payment\Concerns\InteractsWithPaymentProfiles;
use InOtherShops\Payment\Contracts\HasPaymentProfiles;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Customer extends Model implements HasAddresses, HasPaymentProfiles
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    use InteractsWithAddresses;
    use InteractsWithPaymentProfiles;

    protected static function newFactory(): CustomerFactory
    {
        return CustomerFactory::new();
    }

    protected $guarded = [];

    public function authenticatable(): MorphTo
    {
        return $this->morphTo('authenticatable');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Commerce::customerGroup()::class, 'customer_group_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Commerce::order()::class);
    }
}
