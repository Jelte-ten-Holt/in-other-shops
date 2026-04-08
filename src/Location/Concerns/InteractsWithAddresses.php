<?php

declare(strict_types=1);

namespace InOtherShops\Location\Concerns;

use InOtherShops\Location\Location;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait InteractsWithAddresses
{
    public function addresses(): MorphMany
    {
        $model = Location::address();

        return $this->morphMany($model::class, 'addressable');
    }
}
