<?php

declare(strict_types=1);

namespace InOtherShops\Location;

use InOtherShops\Location\Models\Address;

final class Location
{
    public static function address(): Address
    {
        $class = config('location.models.address', Address::class);

        return new $class;
    }
}
