<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Concerns;

use InOtherShops\Shipping\Shipping;
use Illuminate\Database\Eloquent\Relations\MorphOne;

trait InteractsWithShipment
{
    public function shipment(): MorphOne
    {
        $model = Shipping::shipment();

        return $this->morphOne($model::class, 'shippable');
    }
}
