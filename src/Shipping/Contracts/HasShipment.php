<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use InOtherShops\Shipping\Models\Shipment;

interface HasShipment
{
    /**
     * @return MorphMany<Shipment, $this>
     */
    public function shipments(): MorphMany;
}
