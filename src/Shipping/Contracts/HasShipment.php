<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Contracts;

use InOtherShops\Shipping\Models\Shipment;
use Illuminate\Database\Eloquent\Relations\MorphOne;

interface HasShipment
{
    /**
     * @return MorphOne<Shipment, $this>
     */
    public function shipment(): MorphOne;
}
