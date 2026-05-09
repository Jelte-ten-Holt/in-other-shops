<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use InOtherShops\Shipping\Shipping;

trait InteractsWithShipment
{
    public function shipments(): MorphMany
    {
        return $this->morphMany(Shipping::shipment(), 'shippable');
    }
}
